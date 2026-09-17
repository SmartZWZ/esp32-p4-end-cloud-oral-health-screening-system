$ErrorActionPreference = 'Stop'
$python = 'D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-calculus-unetpp-gpu\python.exe'
$script = Join-Path $PSScriptRoot 'calculus_infer.py'

if (-not (Test-Path -LiteralPath $python)) { throw "未找到 Conda 环境：$python" }
Add-Type -AssemblyName System.Windows.Forms
$dialog = New-Object System.Windows.Forms.OpenFileDialog
$dialog.Filter = 'Image files|*.jpg;*.jpeg;*.png;*.bmp;*.webp|All files|*.*'
if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { exit }
$input = $dialog.FileName
$output = Join-Path (Split-Path $input) (([IO.Path]::GetFileNameWithoutExtension($input)) + '_calculus_overlay.png')
& $python $script --image $input --output $output --threshold 0.4
Start-Process $output
