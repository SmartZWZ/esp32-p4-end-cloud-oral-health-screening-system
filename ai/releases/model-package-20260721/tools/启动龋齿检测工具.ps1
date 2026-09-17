$ErrorActionPreference = 'Stop'
$python = 'D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu\python.exe'
$script = Join-Path $PSScriptRoot 'caries_gui.py'

if (-not (Test-Path -LiteralPath $python)) {
    throw "找不到训练环境的 Python：$python"
}
& $python $script
