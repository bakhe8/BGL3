$ErrorActionPreference = "Stop"

$root = "C:\Users\Bakheet\Documents\Projects\BGL3"
Set-Location $root

# Ensure automation runs even during user activity.
$env:BGL_DIAGNOSTIC_IGNORE_IDLE = "1"
# Enforce no-approval mode for scheduled diagnostics.
$env:BGL_APPROVALS_ENABLED = "0"
$env:BGL_FORCE_NO_HUMAN_APPROVALS = "1"
$env:BGL_RUN_SOURCE = "scheduled_task"
$env:BGL_RUN_TRIGGER = "TaskScheduler"
$env:BGL_RUN_TASK_NAME = "BGL3 Master Verify"

$python = Join-Path $root ".bgl_core\.venv312\Scripts\python.exe"
$script = Join-Path $root ".bgl_core\brain\master_verify.py"
$logDir = Join-Path $root ".bgl_core\logs"
$logFile = Join-Path $logDir "scheduled_master_verify.log"
$statusFile = Join-Path $logDir "diagnostic_status.json"
$lockFile = Join-Path $logDir "master_verify.lock"

function Write-Log {
    param(
        [Parameter(Mandatory=$true)][string]$Message
    )

    $line = [string]$Message
    $attempts = 0
    while ($attempts -lt 8) {
        try {
            Add-Content -Path $logFile -Value $line -Encoding UTF8 -ErrorAction Stop
            return
        }
        catch {
            Start-Sleep -Milliseconds (120 * ($attempts + 1))
            $attempts++
        }
    }

    try {
        $fallback = Join-Path $logDir ("scheduled_master_verify.fallback.{0}.log" -f (Get-Date -Format "yyyyMMdd"))
        Add-Content -Path $fallback -Value $line -Encoding UTF8 -ErrorAction SilentlyContinue
    }
    catch {
        # Do not fail the scheduler for logging-only issues.
    }
}

if (!(Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir | Out-Null
}

$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
Write-Log "`n=== Scheduled master_verify @ $timestamp ==="

try {
    $who = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $sessionId = (Get-Process -Id $PID).SessionId
    $parentPid = (Get-CimInstance Win32_Process -Filter "ProcessId=$PID").ParentProcessId
    $parentCmd = (Get-CimInstance Win32_Process -Filter "ProcessId=$parentPid").CommandLine
    Write-Log "Invoker=$who; SessionId=$sessionId; PID=$PID; ParentPID=$parentPid; ParentCmd=$parentCmd"
}
catch {
    Write-Log "Invoker metadata unavailable: $($_.Exception.Message)"
}

# Skip gracefully if a live master_verify lock already exists (avoid overlapping runs).
try {
    if (Test-Path $lockFile) {
        $raw = (Get-Content -Path $lockFile -Raw -ErrorAction Stop).Trim()
        if ($raw) {
            $parts = $raw.Split('|')
            if ($parts.Count -gt 0 -and ($parts[0] -as [int])) {
                $lockPid = [int]$parts[0]
                $proc = Get-Process -Id $lockPid -ErrorAction SilentlyContinue
                if ($proc) {
                    Write-Log "Skip: active master_verify lock detected (PID=$lockPid)."
                    if (Test-Path $statusFile) {
                        try {
                            $statusRaw = Get-Content -Path $statusFile -Raw -ErrorAction Stop
                            if ($statusRaw) {
                                $statusJson = $statusRaw | ConvertFrom-Json
                                if ($statusJson -and $statusJson.status -eq 'running') {
                                    Write-Log "Skip confirmed by diagnostic_status=running."
                                }
                            }
                        }
                        catch {
                            Write-Log "Status check warning: $($_.Exception.Message)"
                        }
                    }
                    exit 0
                }
            }
        }
    }
}
catch {
    Write-Log "Lock precheck warning: $($_.Exception.Message)"
}

$stdoutFile = Join-Path $logDir ("scheduled_master_verify.stdout.{0}.log" -f ([guid]::NewGuid().ToString("N")))
$stderrFile = Join-Path $logDir ("scheduled_master_verify.stderr.{0}.log" -f ([guid]::NewGuid().ToString("N")))

$proc = Start-Process -FilePath $python -ArgumentList @($script) -PassThru -WindowStyle Hidden -RedirectStandardOutput $stdoutFile -RedirectStandardError $stderrFile
Write-Log "Started master_verify process PID=$($proc.Id)"

$pollSec = 5
$completionGraceSec = 25
$maxRuntimeSec = 5400
$runStart = Get-Date
$completionSeenAt = $null
$completionState = ""
$watchdogTerminated = $false
$runtimeTimedOut = $false

while (-not $proc.HasExited) {
    Start-Sleep -Seconds $pollSec

    if (Test-Path $statusFile) {
        try {
            $statusRaw = Get-Content -Path $statusFile -Raw -ErrorAction Stop
            if ($statusRaw) {
                $statusJson = $statusRaw | ConvertFrom-Json
                $state = [string]($statusJson.status)
                if ($state -in @('complete', 'cached', 'skipped', 'deferred_user_active')) {
                    if ($null -eq $completionSeenAt) {
                        $completionSeenAt = Get-Date
                        $completionState = $state
                        Write-Log "Watchdog observed completion state=$state run_id=$($statusJson.run_id)"
                    }
                }
            }
        }
        catch {
            Write-Log "Watchdog status read warning: $($_.Exception.Message)"
        }
    }

    if ($null -ne $completionSeenAt) {
        $elapsedCompletion = ((Get-Date) - $completionSeenAt).TotalSeconds
        if ($elapsedCompletion -ge $completionGraceSec -and -not $proc.HasExited) {
            try {
                Stop-Process -Id $proc.Id -Force -ErrorAction Stop
                $watchdogTerminated = $true
                Write-Log "Watchdog terminated lingering process PID=$($proc.Id) after completion state=$completionState"
            }
            catch {
                Write-Log "Watchdog terminate warning: $($_.Exception.Message)"
            }
            break
        }
    }

    $runtime = ((Get-Date) - $runStart).TotalSeconds
    if ($runtime -ge $maxRuntimeSec -and -not $proc.HasExited) {
        try {
            Stop-Process -Id $proc.Id -Force -ErrorAction Stop
            $watchdogTerminated = $true
            $runtimeTimedOut = $true
            Write-Log "Watchdog hard-timeout termination PID=$($proc.Id) runtime_sec=$([int]$runtime)"
        }
        catch {
            Write-Log "Watchdog hard-timeout warning: $($_.Exception.Message)"
        }
        break
    }
}

try {
    if (Test-Path $stdoutFile) {
        $stdoutContent = Get-Content -Path $stdoutFile -Raw -ErrorAction SilentlyContinue
        if ($stdoutContent) {
            Write-Log $stdoutContent
        }
    }
    if (Test-Path $stderrFile) {
        $stderrContent = Get-Content -Path $stderrFile -Raw -ErrorAction SilentlyContinue
        if ($stderrContent) {
            Write-Log "[stderr]"
            Write-Log $stderrContent
        }
    }
}
catch {
    Write-Log "Log merge warning: $($_.Exception.Message)"
}

$exitCode = 0
if ($watchdogTerminated) {
    if ($runtimeTimedOut) {
        $exitCode = 1
    }
    elseif ($completionState -in @('complete', 'cached', 'skipped', 'deferred_user_active')) {
        $exitCode = 0
    }
    else {
        $exitCode = 1
    }
}
elseif ($proc.HasExited) {
    $exitCode = $proc.ExitCode
    if ($null -eq $exitCode) {
        $exitCode = 0
    }
}
else {
    $exitCode = 1
}

# Normalize lock-blocked/deferred runs as successful scheduler outcomes.
if ($exitCode -ne 0 -and (Test-Path $statusFile)) {
    try {
        $statusRaw = Get-Content -Path $statusFile -Raw -ErrorAction Stop
        if ($statusRaw) {
            $statusJson = $statusRaw | ConvertFrom-Json
            $status = [string]($statusJson.status)
            $stage = [string]($statusJson.stage)
            $reason = [string]($statusJson.reason)
            if (
                ($status -eq 'skipped' -and $stage -eq 'lock_blocked') -or
                ($status -eq 'deferred_user_active') -or
                ($status -eq 'cached')
            ) {
                Write-Log "Normalizing exit code to 0 for status=$status stage=$stage reason=$reason"
                exit 0
            }
        }
    }
    catch {
        Write-Log "Exit normalization warning: $($_.Exception.Message)"
    }
}

try { Remove-Item -Path $stdoutFile -Force -ErrorAction SilentlyContinue } catch {}
try { Remove-Item -Path $stderrFile -Force -ErrorAction SilentlyContinue } catch {}

exit $exitCode
