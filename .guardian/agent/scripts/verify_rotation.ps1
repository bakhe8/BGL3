param(
    [int]$FilesCount = 50
)

$ErrorActionPreference = 'Stop'
$agentDir = Resolve-Path (Join-Path $PSScriptRoot '..')
$inbox = Join-Path $agentDir 'commands\inbox'
$testsDir = Join-Path $agentDir 'tests\rotation_verify'

New-Item -ItemType Directory -Force -Path $testsDir | Out-Null

function Send-Rotate {
    param([string]$id)
    $payload = @{ op = 'rotate_logs'; id = $id }
    $json = $payload | ConvertTo-Json -Compress
    $path = Join-Path $inbox "$id.json"
    Set-Content -Path "$path.tmp" -Value $json -Encoding UTF8
    Move-Item -Force -Path "$path.tmp" -Destination $path
}

Write-Host "Starting simultaneous Write and Rotate test..."

# Start background writes
$writeJob = Start-Job -ScriptBlock {
    param($dir, $count)
    for ($i = 1; $i -le $count; $i++) {
        Set-Content -Path (Join-Path $dir "file_$i.txt") -Value "Content $i"
        Start-Sleep -Milliseconds 10
    }
} -ArgumentList $testsDir, $FilesCount

# Trigger rotations during writes
for ($j = 1; $j -le 3; $j++) {
    Send-Rotate -id "verify-rotate-$j"
    Start-Sleep -Milliseconds 150
}

Wait-Job $writeJob | Out-Null
Receive-Job $writeJob
Write-Host "Test completed. verify logs for $FilesCount events."
