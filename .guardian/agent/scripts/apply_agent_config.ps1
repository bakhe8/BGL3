# Apply recommended agent configuration
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/apply_agent_config.ps1

$ErrorActionPreference = 'Stop'

$agentDir = Resolve-Path (Join-Path $PSScriptRoot '..')
$agentConfigPath = Join-Path $agentDir 'config.yml'

if (!(Test-Path $agentDir)) {
  New-Item -ItemType Directory -Force -Path $agentDir | Out-Null
}

$yml = @'
watch:
  path: .
  recursive: true

ignore:
  paths:
    - agent/events.log
    - agent/events.jsonl
    - agent/status.json
    - agent/status.json.tmp
    - agent/stop_agent.ps1
    - agent/commands
  globs:
    - ".git/**"
    - "agent/commands/**"
    - "vendor/**"
    - "node_modules/**"
    - "dist/**"
    - "build/**"
    - "__pycache__/**"

features:
  console_log: true
  text_log: true
  jsonl_log: true
  status: true
  event_types: ["created", "modified", "deleted"]

behavior:
  debounce_ms: 150
  aggregate_window_ms: 1000
  aggregate_include_debounced: true

logging:
  level: "INFO"
  file: agent/events.log

jsonl:
  file: agent/events.jsonl

status:
  file: agent/status.json
  interval_sec: 5.0

commands:
  enabled: true
  inbox: agent/commands/inbox
  outbox: agent/commands/outbox
  poll_interval_ms: 1000
  invalid:
    quarantine_enabled: true
    max_retries: 3
    invalid_dir: agent/commands/inbox/invalid
'@

# Write atomically
$tempPath = "$agentConfigPath.tmp"
$yml | Out-File -FilePath $tempPath -Encoding UTF8 -Force
Move-Item -Force -Path $tempPath -Destination $agentConfigPath

Write-Host "Applied agent config to $agentConfigPath"
