@echo off
chcp 65001 >nul
cd /d "%~dp0"

where python >nul 2>nul
if errorlevel 1 (
    echo 未找到 Python。请先安装 Python 3.10 或更高版本，并勾选“Add Python to PATH”。
    pause
    exit /b 1
)

python -c "import cv2, numpy, PIL" >nul 2>nul
if errorlevel 1 (
    echo 检测到运行依赖尚未安装，将开始自动安装。
    call "%~dp0安装依赖.bat"
    if errorlevel 1 exit /b 1
)

python app.py
if errorlevel 1 (
    echo.
    echo 程序异常退出，请根据上方信息检查环境。
    pause
)
