param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('caries', 'restoration')]
    [string]$Model,

    [Parameter(Mandatory = $true)]
    [string]$Source,

    [double]$Confidence = -1,
    [string]$Device = '0'
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$args = @('.\infer.py', '--model', $Model, '--source', $Source, '--device', $Device)
if ($Confidence -ge 0) {
    $args += @('--conf', $Confidence)
}
python @args
