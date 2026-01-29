param(
    [string]$TestID = $(Get-Date -Format 'yyyyMMdd-HHmmss')
)

$ErrorActionPreference = 'Stop'
$agentDir = Resolve-Path (Join-Path $PSScriptRoot '..')
$inbox = Join-Path $agentDir 'commands\inbox'
$inbox = Join-Path $agentDir 'commands\inbox'
$testsDir = Join-Path $agentDir 'tests\stress'
$burstDir = Join-Path $testsDir 'burst'

# Setup directories
New-Item -ItemType Directory -Force -Path $burstDir | Out-Null

function Send-Command {
    param([string]$op, [string]$id, [hashtable]$params = @{})
    $payload = @{ op = $op; id = $id }
    foreach ($key in $params.Keys) { $payload[$key] = $params[$key] }
    $json = $payload | ConvertTo-Json -Compress
    $path = Join-Path $inbox "$id.json"
    Set-Content -Path "$path.tmp" -Value $json -Encoding UTF8
    Move-Item -Force -Path "$path.tmp" -Destination $path
}

Write-Host "--- Stage 1: Preparation (Rotate Logs) ---"
Send-Command -op "rotate_logs" -id "stress-rotate-$TestID"
Start-Sleep -Seconds 2

Write-Host "--- Stage 2: Burst Test (100 files) ---"
$startTime = Get-Date
for ($i = 1; $i -le 100; $i++) {
    Set-Content -Path (Join-Path $burstDir "file_$i.txt") -Value "Burst content $i"
}
$endTime = Get-Date
$duration = ($endTime - $startTime).TotalSeconds
Write-Host "Burst of 100 files created in $duration seconds."

Write-Host "--- Stage 3: Deep Nesting Test ---"
$deepDir = Join-Path $testsDir "d1\d2\d3\d4\d5\d6\d7\d8\d9\d10"
New-Item -ItemType Directory -Force -Path $deepDir | Out-Null
Set-Content -Path (Join-Path $deepDir "deep.txt") -Value "Deep content"

Write-Host "--- Stage 4: Command Flood ---"
for ($i = 1; $i -le 10; $i++) {
    Send-Command -op "ping" -id "stress-ping-$i-$TestID"
}

Write-Host "--- Stage 5: Invalid Command Robustness ---"
for ($i = 1; $i -le 5; $i++) {
    $badPath = Join-Path $inbox "corrupt-$i-$TestID.json"
    Set-Content -Path $badPath -Value "{ 'op': 'ping', INVALID_JSON_HERE: " -Encoding UTF8
}

Write-Host "--- Stage 6: Debounce/Aggregate Stress ---"
$debounceFile = Join-Path $testsDir "debounce_stress.txt"
Set-Content -Path $debounceFile -Value "Initial"
for ($i = 1; $i -le 50; $i++) {
    Add-Content -Path $debounceFile -Value "Update $i"
    # No sleep or very minimal to trigger debounce/aggregate
}

Write-Host "Test Round $TestID Completed."
Write-Host "Check agent logs for results."
