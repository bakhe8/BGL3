# BGL3 Server Toggle
# يقوم بتبديل حالة السيرفر - تشغيل أو إيقاف

$ErrorActionPreference = "SilentlyContinue"
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $projectPath "server.pid"
$toolServerPort = 8891
$copilotBundle = Join-Path $projectPath "agentfrontend\app\copilot\dist\copilot-widget.js"

function Kill-Port {
    param([int]$Port)
    $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    foreach ($c in $conns) {
        try {
            Stop-Process -Id $c.OwningProcess -Force -ErrorAction SilentlyContinue
        } catch {}
    }
}

# التحقق من حالة السيرفر
if (Test-Path $pidFile) {
    $processId = Get-Content $pidFile
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    
    if ($process) {
        # السيرفر يعمل - سنقوم بإيقافه
        Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
        Write-Host "   إيقاف السيرفر" -ForegroundColor Yellow
        Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
        Write-Host ""
        
        Stop-Process -Id $processId -Force
        Remove-Item $pidFile -Force
        
        Write-Host "✓ تم إيقاف السيرفر (PID: $processId)" -ForegroundColor Green
        Write-Host ""
        Start-Sleep -Seconds 2
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
    if (-not (Test-Path "node_modules")) {
        Start-Process -FilePath "npm" -ArgumentList "install" -WindowStyle Hidden -PassThru -Wait | Out-Null
    }
    Start-Process -FilePath "npm" -ArgumentList "run build" -WindowStyle Hidden -PassThru -Wait | Out-Null
    Pop-Location
}

# تشغيل جسر الأدوات/الشات إذا لم يكن مستمعاً على 8891
function Ensure-ToolServer {
    $listening = Get-NetTCPConnection -LocalPort $toolServerPort -State Listen -ErrorAction SilentlyContinue
    if (-not $listening) {
        Write-Host "↻ تشغيل tool_server.py على المنفذ $toolServerPort" -ForegroundColor Cyan
        Start-Process -FilePath "python" -ArgumentList "`"$projectPath\scripts\tool_server.py`" --port $toolServerPort" -WindowStyle Hidden
    }
}

Ensure-CopilotBuild
Ensure-ToolServer

# [Gatekeeper] التحقق من وجود دستور المشروع (Architectural Constitution)
$rulesFile = Join-Path $projectPath ".bgl_core\brain\domain_rules.yml"
if (-not (Test-Path $rulesFile)) {
    Write-Host "✗ خطأ قاتل: ملف القواعد المعمارية (domain_rules.yml) مفقود!" -ForegroundColor Red
    Write-Host "  لا يمكن للوكيل حماية المشروع بدون هذا الملف." -ForegroundColor Gray
    exit 1
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
$processInfo.Arguments = "-S localhost:8000 server.php"
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
    
    Write-Host "✓ تم تشغيل السيرفر بنجاح!" -ForegroundColor Green
    Write-Host "  - PID: $($process.Id)" -ForegroundColor Gray
    Write-Host "  - العنوان: http://localhost:8000" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "لإيقاف السيرفر: قم بتشغيل هذا الملف مرة أخرى" -ForegroundColor Yellow
    Write-Host ""
    
    # فتح المتصفح
    Start-Sleep -Milliseconds 500
    Start-Process "http://localhost:8000"
    
    Start-Sleep -Seconds 2
} else {
    Write-Host "✗ فشل في تشغيل السيرفر" -ForegroundColor Red
    Start-Sleep -Seconds 3
    exit 1
}
