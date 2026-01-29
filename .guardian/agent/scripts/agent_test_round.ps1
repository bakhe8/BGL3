param(
  [string]$Round = $(Get-Date -Format 'yyyyMMdd-HHmmss'),
  [int]$DebounceWrites = 5,
  [int]$DebounceIntervalMs = 50
)

$ErrorActionPreference = 'Stop'

$agentDir = Resolve-Path (Join-Path $PSScriptRoot '..')
$root = Resolve-Path (Join-Path $agentDir '..')
$testsDir = Join-Path $agentDir 'tests'
$inbox = Join-Path $agentDir 'commands' | Join-Path -ChildPath 'inbox'
$outbox = Join-Path $agentDir 'commands' | Join-Path -ChildPath 'outbox'

New-Item -ItemType Directory -Force -Path $testsDir | Out-Null

New-Item -ItemType Directory -Force -Path $inbox | Out-Null
New-Item -ItemType Directory -Force -Path $outbox | Out-Null

function Write-JsonFile {
  param(
    [string]$Path,
    [object]$Object
  )
  $json = $Object | ConvertTo-Json -Compress -Depth 6
  $tmp = "$Path.tmp"
  Set-Content -Path $tmp -Value $json -Encoding UTF8
  Move-Item -Force -Path $tmp -Destination $Path
}

# 1) ping
Write-JsonFile -Path (Join-Path $inbox "ping-$Round.json") -Object @{ op = 'ping'; id = "ping-$Round" }

# 2) add_ignored
Write-JsonFile -Path (Join-Path $inbox "add-ignored-$Round.json") -Object @{ op = 'add_ignored'; id = "add-ignored-$Round"; paths = @('ignored_area'); globs = @('**/*.tmp') }

# 3) get_ignored
Write-JsonFile -Path (Join-Path $inbox "get-ignored-$Round.json") -Object @{ op = 'get_ignored'; id = "get-ignored-$Round" }

# 4) clear_ignored
Write-JsonFile -Path (Join-Path $inbox "clear-ignored-$Round.json") -Object @{ op = 'clear_ignored'; id = "clear-ignored-$Round" }

# 5) rotate_logs
Write-JsonFile -Path (Join-Path $inbox "rotate-logs-$Round.json") -Object @{ op = 'rotate_logs'; id = "rotate-logs-$Round" }

# 6) invalid JSON (bad file)
$badPath = Join-Path $inbox ("bad-$Round.json")
$badTmp = "$badPath.tmp"
Set-Content -Path $badTmp -Value '{ "op": "ping", broken' -Encoding UTF8
Move-Item -Force -Path $badTmp -Destination $badPath

# 7) Debounce test: rapid writes to a file
$debounceFile = Join-Path $testsDir 'debounce_check.txt'
for ($i = 0; $i -lt $DebounceWrites; $i++) {
  Add-Content -Path $debounceFile -Value (Get-Date).ToString('o')
  Start-Sleep -Milliseconds $DebounceIntervalMs
}

Write-Host "Test round '$Round' submitted."
Write-Host "- Commands queued in: $inbox"
Write-Host "- Expect responses in: $outbox"
Write-Host "- Check events: $($agentDir)\events.jsonl and rotated files"
