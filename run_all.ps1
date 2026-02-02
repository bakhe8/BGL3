$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

function Ensure-CopilotBuild {
  $dist = "agentfrontend/app/copilot/dist/copilot-widget.js"
  if (-not (Test-Path $dist)) {
    Write-Host "[build] copilot-widget.js غير موجود، سأبني الواجهة..." -ForegroundColor Yellow
    Push-Location "agentfrontend"
    if (-not (Test-Path "node_modules")) { npm install }
    npm run build
    Pop-Location
  }
}

function Start-ToolServer {
  $script = "scripts/tool_server.py"
  if (-not (Test-Path $script)) {
    throw "لم أجد $script"
  }
  Write-Host "[run] tool_server.py على 8891" -ForegroundColor Cyan
  Start-Process -FilePath "python" -ArgumentList "$script --port 8891" -WindowStyle Hidden | Out-Null
}

function Start-PHPServer {
  Write-Host "[run] PHP server على http://localhost:8000" -ForegroundColor Cyan
  Start-Process -FilePath "php" -ArgumentList "-S localhost:8000 server.php" -WindowStyle Hidden | Out-Null
}

function Check-Ollama {
  $ok = Test-NetConnection -ComputerName "localhost" -Port 11434 -InformationLevel Quiet
  if (-not $ok) {
    Write-Host "[warn] Ollama غير متصل على 11434. شغّله أولاً (ollama serve)." -ForegroundColor Yellow
  } else {
    Write-Host "[ok] Ollama جاهز على 11434" -ForegroundColor Green
  }
}

Ensure-CopilotBuild
Check-Ollama
Start-ToolServer
Start-PHPServer

Write-Host "`nكل شيء يعمل. افتح المتصفح: http://localhost:8000/agent-dashboard.php" -ForegroundColor Green
