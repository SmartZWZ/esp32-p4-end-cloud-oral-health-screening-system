$ErrorActionPreference = "Stop"
$PythonW = "D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu\pythonw.exe"
$Workspace = Split-Path -Parent $MyInvocation.MyCommand.Path
Start-Process -FilePath $PythonW -ArgumentList "`"$Workspace\app.py`"" -WorkingDirectory $Workspace
