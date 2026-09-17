@echo off
setlocal
set "ROOT=%~dp0"
set "KNOWN_PYTHON=D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu\python.exe"

if exist "%KNOWN_PYTHON%" (
  "%KNOWN_PYTHON%" "%ROOT%app.py"
  pause
  exit /b %errorlevel%
)

where conda >nul 2>nul
if not errorlevel 1 (
  conda run -n dental-darkline --no-capture-output python "%ROOT%app.py"
  pause
  exit /b %errorlevel%
)

echo [ERROR] Python environment was not found.
pause
exit /b 1
