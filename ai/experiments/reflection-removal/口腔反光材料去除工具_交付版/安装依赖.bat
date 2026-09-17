@echo off
chcp 65001 >nul
cd /d "%~dp0"

where python >nul 2>nul
if errorlevel 1 (
    echo 未找到 Python。请先安装 Python 3.10 或更高版本，并勾选“Add Python to PATH”。
    pause
    exit /b 1
)

echo 正在安装运行依赖...
python -m pip install -r requirements.txt
if errorlevel 1 (
    echo.
    echo 依赖安装失败，请检查网络连接和 Python 环境。
    pause
    exit /b 1
)

echo.
echo 依赖安装完成。
exit /b 0
