param(
    [int]$BurstCount = 50
)

$ErrorActionPreference = 'Stop'
$agentDir = Resolve-Path (Join-Path $PSScriptRoot '..')
$root = Resolve-Path (Join-Path $agentDir '..')
$eventsFile = Join-Path $agentDir 'events.jsonl'
$testFile = Join-Path $root 'recovery_trigger.txt'

function Get-AgentPid {
    for ($i = 1; $i -le 10; $i++) {
        if (Test-Path (Join-Path $agentDir 'status.json')) {
            try {
                $status = Get-Content (Join-Path $agentDir 'status.json') -Raw | ConvertFrom-Json
                if ($status.pid) { return $status.pid }
            }
            catch {}
        }
        Start-Sleep -Seconds 1
    }
    throw "Failed to get Agent PID from status.json after 10 seconds."
}

Write-Host "Starting Recovery Test (v1.5.0)..."

# Ensure agent is running
if (-not (Test-Path (Join-Path $agentDir 'status.json'))) {
    Write-Host "Starting agent..."
    Start-Process python -ArgumentList "agent/main.py" -WindowStyle Hidden -WorkingDirectory $root
    Start-Sleep -Seconds 3
}

$agent_pid = Get-AgentPid
Write-Host "Agent PID: $agent_pid"

# Start a background burst and KILL the agent mid-way
Write-Host "Starting burst and forced kill..."
$job = Start-Job -ScriptBlock {
    param($file, $count)
    for ($i = 1; $i -le $count; $i++) {
        Add-Content -Path $file -Value "Trigger event $i"
        Start-Sleep -Milliseconds 20
    }
} -ArgumentList $testFile, $BurstCount

Start-Sleep -Seconds 1 # Wait for some events to be processed
Write-Host "KILLING AGENT NOW!"
Stop-Process -Id $agent_pid -Force

Wait-Job $job | Out-Null
Receive-Job $job

Write-Host "Agent killed. Checking integrity..."

# Check JSONL integrity (gracefully skip partial lines at EOF)
try {
    $lines = Get-Content $eventsFile -ErrorAction SilentlyContinue
    $validCount = 0
    $corruptCount = 0
    foreach ($line in $lines) {
        if ($line.Trim() -ne "") {
            try {
                $line | ConvertFrom-Json | Out-Null
                $validCount++
            }
            catch {
                $corruptCount++
            }
        }
    }
    Write-Host "Validated $validCount events. Skipped $corruptCount corrupted/partial lines."
    if ($corruptCount -gt 2) { throw "Too many corrupted lines ($corruptCount)" }
}
catch {
    Write-Error "Integrity check failed: $_"
}

# Restart and check if it recovers metrics
Write-Host "Restarting agent for recovery check..."
Start-Process python -ArgumentList "agent/main.py" -WindowStyle Hidden -WorkingDirectory $root
Start-Sleep -Seconds 5

$newStatus = Get-Content (Join-Path $agentDir 'status.json') -Raw | ConvertFrom-Json
if ($newStatus.uptime_sec -lt 10) {
    Write-Host "Recovery Successful. Metrics active: RAM $($newStatus.metrics.memory_rss_mb) MB"
}
else {
    Write-Warning "Uptime check failed, agent might not have restarted correctly."
}

Remove-Item $testFile -ErrorAction SilentlyContinue
Write-Host "Recovery Test Completed."
