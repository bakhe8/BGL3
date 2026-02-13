param(
    [string]$BaseUrl = "http://localhost:8000",
    [int]$Headless = 1
)

Write-Host "[*] Running agent verification pipeline..."

$env:BGL_BASE_URL = $BaseUrl
$env:BGL_HEADLESS = "$Headless"
$env:BGL_RUN_SCENARIOS = "1"
$env:BGL_FORCE_RATE_LIMIT = "1"
$env:BGL_RUN_SOURCE = "agent_verify"
$env:BGL_RUN_TRIGGER = "manual_script"

$pythonExe = Join-Path $PSScriptRoot ".bgl_core\\.venv312\\Scripts\\python.exe"
if (-not (Test-Path $pythonExe)) {
    $pythonExe = "python"
}

# Start local PHP server (fire-and-forget) if not already running
$phpServer = Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.Path -match "php.exe" }
if (-not $phpServer) {
    Write-Host "[0/4] Starting local PHP server on 8000..."
    Start-Process -FilePath "php" -ArgumentList "-S","localhost:8000","-t","public" -WindowStyle Hidden
    Start-Sleep -Seconds 1
}

Write-Host "[1/4] Indexing project..."
& $pythonExe .bgl_core/brain/indexer.py

Write-Host "[2/4] Running Playwright scenarios..."
& $pythonExe .bgl_core/brain/scenario_runner.py --base-url $BaseUrl --headless $Headless

Write-Host "[3/4] Digesting runtime events..."
& $pythonExe .bgl_core/brain/context_digest.py --hours 24 --limit 500

Write-Host "[4/4] Master technical assurance..."
& $pythonExe .bgl_core/brain/master_verify.py

Write-Host "[+] Agent verification complete."
