$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot
$env:BGL_EXPLORATION = 1
.\.bgl_core\.venv312\Scripts\python.exe scripts/run_verification_cycle.py
