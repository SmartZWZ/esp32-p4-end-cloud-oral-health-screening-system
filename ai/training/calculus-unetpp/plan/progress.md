# Dental-calculus U-Net++ training progress

## Objective

Train a standalone binary semantic-segmentation model from `D:\Downloads\EdgeDownload\PMC11515110`, using only raw mask value `2` as dental calculus. Architecture: U-Net++ with an ImageNet-pretrained ResNet34 encoder.

## Completed

- [x] Created independent workspace.
- [x] Created and verified `dental-calculus-unetpp-gpu` (PyTorch 2.7.0 + CUDA 12.8, RTX 5060 Laptop GPU).
- [x] Grouped by exact original image, removed leakage and generated a binary calculus dataset.
- [x] Trained and evaluated the first model.

## Data governance

- Mask value `2` is the only calculus target.
- Identical originals are grouped before splitting.
- Duplicate groups with contradictory calculus labels are excluded from the first training set.
- 390 positive and 780 negative unique image groups were retained; train/validation/test totals are 819/175/176.

## First-run result

- Training early-stopped at epoch 27. The best checkpoint was epoch 9.
- Best validation Dice: 0.3255.
- Independent test Dice: 0.3055; IoU: 0.1803; recall: 0.5420; precision: 0.2127.
- The later epochs degraded, so `runs/unetpp_resnet34_seed42/checkpoints/best.pt` retains the valid best model.
