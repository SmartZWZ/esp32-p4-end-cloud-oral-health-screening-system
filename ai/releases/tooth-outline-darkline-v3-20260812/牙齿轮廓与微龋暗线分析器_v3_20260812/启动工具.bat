@echo off
setlocal
set "ROOT=%~dp0"
set "KNOWN_PYTHONW=D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu\pythonw.exe"

if exist "%KNOWN_PYTHONW%" (
  start "" "%KNOWN_PYTHONW%" "%ROOT%app.py"
  exit /b 0
)

where conda >nul 2>nul
if not errorlevel 1 (
  start "" conda run -n dental-darkline --no-capture-output pythonw "%ROOT%app.py"
  exit /b 0
)

echo [ERROR] Python environment was not found.
echo Please follow README_部署与使用.md to create the dental-darkline environment first.
pause
exit /b 1
