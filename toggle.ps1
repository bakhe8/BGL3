# BGL3 Server Toggle
# يقوم بتبديل حالة السيرفر - تشغيل أو إيقاف

$ErrorActionPreference = "Stop"
$logDir = Join-Path $PSScriptRoot "storage\logs"
$logFile = Join-Path $logDir "toggle.log"

function Show-Error {
    param([string]$Message)
    try {
        if (-not (Test-Path $logDir)) {
            New-Item -ItemType Directory -Path $logDir -Force | Out-Null
        }
        $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Add-Content -Path $logFile -Value "[$stamp] ERROR: $Message"
    } catch {}
    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show(
            $Message,
            "BGL3 Server",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } catch {}
}

function Fail {
    param([string]$Message)
    throw $Message
}
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $projectPath "server.pid"
$toolServerPort = 8891
$copilotBundle = Join-Path $projectPath "agentfrontend\app\copilot\dist\copilot-widget.js"

function Resolve-Npm {
    $cmd = Get-Command npm.cmd -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) { return $cmd.Source }
    $cmd = Get-Command npm -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) { return $cmd.Source }
    return $null
}

function Kill-Port {
    param([int]$Port)
    $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    foreach ($c in $conns) {
        try {
            Stop-Process -Id $c.OwningProcess -Force -ErrorAction SilentlyContinue
        } catch {}
    }
}

try {
    # التحقق من حالة السيرفر
    if (Test-Path $pidFile) {
        $processId = Get-Content $pidFile
        $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
        
        if ($process) {
            # السيرفر يعمل - سنقوم بإيقافه
            Stop-Process -Id $processId -Force
            Remove-Item $pidFile -Force
            exit 0
        }
    }

# السيرفر متوقف - سنقوم بتشغيله
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host "   تشغيل السيرفر" -ForegroundColor Green
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host ""

# تنظيف المنافذ قبل التشغيل (8000 للسيرفر، 8891 للجسر)
Kill-Port 8000
Kill-Port $toolServerPort

# ضمان وجود حزمة الواجهة (تبقى نفس الطريقة والملفات)
function Ensure-CopilotBuild {
    Push-Location (Join-Path $projectPath "agentfrontend")
    $npmPath = Resolve-Npm
    if (-not $npmPath) {
        Write-Host "✗ npm غير متوفر في PATH. ثبّت Node.js أو أضف npm.cmd إلى PATH." -ForegroundColor Red
        Pop-Location
        exit 1
    }
    if (-not (Test-Path "node_modules")) {
        Start-Process -FilePath $npmPath -ArgumentList "install" -WindowStyle Hidden -PassThru -Wait | Out-Null
    }
    Start-Process -FilePath $npmPath -ArgumentList "run build" -WindowStyle Hidden -PassThru -Wait | Out-Null
    Pop-Location
}

# تشغيل جسر الأدوات/الشات إذا لم يكن مستمعاً على 8891
function Ensure-ToolServer {
    $listening = Get-NetTCPConnection -LocalPort $toolServerPort -State Listen -ErrorAction SilentlyContinue
    if (-not $listening) {
        Write-Host "↻ تشغيل tool_server.py على المنفذ $toolServerPort" -ForegroundColor Cyan
        $pythonExe = Join-Path $projectPath ".bgl_core\\.venv312\\Scripts\\python.exe"
        if (-not (Test-Path $pythonExe)) {
            $pythonExe = "python"
        }
        Start-Process -FilePath $pythonExe -ArgumentList "`"$projectPath\scripts\tool_server.py`" --port $toolServerPort" -WindowStyle Hidden
    }
}

    Ensure-CopilotBuild
    Ensure-ToolServer

# [Gatekeeper] التحقق من وجود دستور المشروع (Architectural Constitution)
$rulesFile = Join-Path $projectPath ".bgl_core\brain\domain_rules.yml"
    if (-not (Test-Path $rulesFile)) {
        Fail "ملف القواعد المعمارية (domain_rules.yml) مفقود. لا يمكن تشغيل النظام بدون هذا الملف."
    }

# إنشاء مجلد logs إذا لم يكن موجوداً
$logsDir = Join-Path $projectPath "storage\logs"
if (-not (Test-Path $logsDir)) {
    New-Item -ItemType Directory -Path $logsDir -Force | Out-Null
}

# تحديد مسار PHP (محلي أو من النظام)
$phpPath = Join-Path $projectPath "php\php.exe"
if (-not (Test-Path $phpPath)) {
    # استخدام PHP من النظام
    $phpPath = "php"
}

# تشغيل السيرفر في الخلفية
$processInfo = New-Object System.Diagnostics.ProcessStartInfo
$processInfo.FileName = $phpPath
$processInfo.Arguments = "-S 0.0.0.0:8000 server.php"
$processInfo.WorkingDirectory = $projectPath
$processInfo.UseShellExecute = $false
$processInfo.CreateNoWindow = $true
$processInfo.RedirectStandardOutput = $true
$processInfo.RedirectStandardError = $true

$process = New-Object System.Diagnostics.Process
$process.StartInfo = $processInfo

# بدء العملية
$started = $process.Start()

    if ($started) {
        # حفظ PID
        $process.Id | Out-File -FilePath $pidFile -Encoding UTF8
        
        # فتح المتصفح
        Start-Sleep -Milliseconds 500
        Start-Process "http://localhost:8000"
        exit 0
    } else {
        Fail "فشل في تشغيل السيرفر."
    }
} catch {
    $msg = $_.Exception.Message
    if (-not $msg) { $msg = "حدث خطأ غير معروف أثناء تشغيل النظام." }
    Show-Error $msg
    exit 1
}
