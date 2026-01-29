param(
  [int]$DebounceMs = 150,
  [int]$AggregateWindowMs = 1000,
  [bool]$IncludeDebounced = $true,
  [bool]$QuarantineEnabled = $true,
  [int]$InvalidMaxRetries = 3,
  [string]$InvalidDir = 'agent/commands/inbox/invalid'
)

$ErrorActionPreference = 'Stop'
$cfg = Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..')) 'config.yml'
if (!(Test-Path $cfg)) { throw "config.yml not found at $cfg" }

# Read, patch, and write atomically
$content = Get-Content -Raw -Path $cfg

# features
$content = $content -replace 'debounce_ms:\s*\d+', "debounce_ms: $DebounceMs"
$content = $content -replace 'aggregate_window_ms:\s*\d+', "aggregate_window_ms: $AggregateWindowMs"
$includeStr = if ($IncludeDebounced) { 'true' } else { 'false' }
$content = $content -replace 'aggregate_include_debounced:\s*(true|false)', "aggregate_include_debounced: $includeStr"

# commands.invalid
$quarantineStr = if ($QuarantineEnabled) { 'true' } else { 'false' }
$content = $content -replace 'quarantine_enabled:\s*(true|false)', "quarantine_enabled: $quarantineStr"
$content = $content -replace 'max_retries:\s*\d+', "max_retries: $InvalidMaxRetries"
# Replace the whole value after 'invalid_dir:' up to end-of-line
$content = $content -replace 'invalid_dir:\s*.*', "invalid_dir: $InvalidDir"

$tmp = "$cfg.tmp"
Set-Content -Path $tmp -Value $content -Encoding UTF8
Move-Item -Force -Path $tmp -Destination $cfg
Write-Host "Updated: debounce_ms=$DebounceMs aggregate_window_ms=$AggregateWindowMs include_debounced=$includeStr quarantine_enabled=$quarantineStr max_retries=$InvalidMaxRetries invalid_dir=$InvalidDir"
