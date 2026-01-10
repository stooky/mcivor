@echo off
echo ========================================
echo   SMB Website Boilerplate - Dev Server
echo ========================================
echo.

:: Kill any existing Node processes running on port 4321
echo Checking for existing processes on port 4321...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":4321" ^| findstr "LISTENING"') do (
    echo Killing process with PID: %%a
    taskkill /F /PID %%a >nul 2>&1
)

:: Small delay to ensure port is released
timeout /t 2 /nobreak >nul

:: Change to script directory
cd /d "%~dp0"

:: Check if node_modules exists
if not exist "node_modules" (
    echo.
    echo Installing dependencies...
    call npm install
    echo.
)

:: Start the dev server
echo.
echo Starting Astro dev server...
echo Press Ctrl+C to stop the server
echo.
call npm run dev
