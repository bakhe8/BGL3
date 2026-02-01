$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot
$env:BGL_EXPLORATION = 1
.\.bgl_core\.venv312\Scripts\python.exe .bgl_core/brain/scenario_runner.py --headless 0 --keep-open 0 --max-pages 1 --idle-timeout 300 --base-url http://localhost:8000 --include basic_pages
