param(
    [string]$Model = "yolo11s.pt",
    [int]$Epochs = 150,
    [int]$ImageSize = 1024,
    [string]$Device = "0"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

yolo detect train `
    model=$Model `
    data="$root/tooth_numbering.yaml" `
    epochs=$Epochs `
    imgsz=$ImageSize `
    batch=-1 `
    device=$Device `
    workers=4 `
    seed=42 `
    deterministic=True `
    patience=30 `
    cos_lr=True `
    degrees=10 `
    translate=0.05 `
    scale=0.15 `
    fliplr=0.5 `
    mosaic=0.2 `
    close_mosaic=20 `
    project="C:/tooth-numbering-runs" `
    name="yolo11s_fdi20_baseline_150"
