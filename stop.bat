@echo off
cd /d "%~dp0"
:: Force kill PHP process to stop the server
taskkill /F /IM php.exe /T >nul 2>&1
