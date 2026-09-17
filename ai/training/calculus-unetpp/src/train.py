"""Train a binary U-Net++ dental-calculus semantic-segmentation model."""

from __future__ import annotations

import argparse
import csv
import json
import random
from dataclasses import asdict, dataclass
from pathlib import Path

import albumentations as A
import cv2
import numpy as np
import segmentation_models_pytorch as smp
import torch
import torch.nn.functional as F
from torch import nn
from torch.utils.data import DataLoader, Dataset


@dataclass
class Settings:
    width: int = 672
    height: int = 448
    batch_size: int = 4
    epochs: int = 80
    patience: int = 18
    learning_rate: float = 1e-3
    weight_decay: float = 1e-4
    seed: int = 42
    workers: int = 2


def seed_everything(seed: int) -> None:
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)


class CalculusDataset(Dataset):
    def __init__(self, image_dir: Path, mask_dir: Path, transform: A.Compose):
        self.images = sorted(image_dir.glob("*.jpg"))
        self.mask_dir = mask_dir
        self.transform = transform
        if not self.images:
            raise RuntimeError(f"No images found in {image_dir}")

    def __len__(self) -> int:
        return len(self.images)

    def __getitem__(self, index: int):
        image_path = self.images[index]
        # cv2.imread cannot reliably open Unicode paths on Windows; decode bytes instead.
        image_bytes = np.fromfile(image_path, dtype=np.uint8)
        mask_bytes = np.fromfile(self.mask_dir / f"{image_path.stem}.png", dtype=np.uint8)
        image = cv2.imdecode(image_bytes, cv2.IMREAD_COLOR)
        mask = cv2.imdecode(mask_bytes, cv2.IMREAD_GRAYSCALE)
        if image is None or mask is None:
            raise RuntimeError(f"Cannot read sample {image_path}")
        image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
        transformed = self.transform(image=image, mask=(mask > 127).astype(np.float32))
        tensor_image = torch.from_numpy(transformed["image"].transpose(2, 0, 1)).float()
        tensor_mask = torch.from_numpy(transformed["mask"]).unsqueeze(0).float()
        return tensor_image, tensor_mask, image_path.name


class DiceBCELoss(nn.Module):
    def forward(self, logits: torch.Tensor, targets: torch.Tensor) -> torch.Tensor:
        bce = F.binary_cross_entropy_with_logits(logits, targets)
        probabilities = torch.sigmoid(logits)
        intersection = (probabilities * targets).sum(dim=(1, 2, 3))
        denominator = probabilities.sum(dim=(1, 2, 3)) + targets.sum(dim=(1, 2, 3))
        dice_loss = 1 - ((2 * intersection + 1.0) / (denominator + 1.0)).mean()
        return 0.5 * bce + 0.5 * dice_loss


def metrics(logits: torch.Tensor, targets: torch.Tensor) -> dict[str, int]:
    predictions = torch.sigmoid(logits) >= 0.5
    truth = targets >= 0.5
    return {
        "tp": int((predictions & truth).sum().item()),
        "fp": int((predictions & ~truth).sum().item()),
        "fn": int((~predictions & truth).sum().item()),
    }


def summarize(stats: dict[str, float]) -> dict[str, float]:
    tp, fp, fn = stats["tp"], stats["fp"], stats["fn"]
    dice = (2 * tp + 1.0) / (2 * tp + fp + fn + 1.0)
    iou = (tp + 1.0) / (tp + fp + fn + 1.0)
    precision = (tp + 1.0) / (tp + fp + 1.0)
    recall = (tp + 1.0) / (tp + fn + 1.0)
    return {"loss": stats["loss"] / stats["samples"], "dice": dice, "iou": iou, "precision": precision, "recall": recall}


def run_epoch(model, loader, criterion, optimizer, scaler, device, training: bool) -> dict[str, float]:
    model.train(training)
    aggregate = {"loss": 0.0, "samples": 0, "tp": 0, "fp": 0, "fn": 0}
    context = torch.enable_grad if training else torch.no_grad
    with context():
        for images, masks, _ in loader:
            images, masks = images.to(device, non_blocking=True), masks.to(device, non_blocking=True)
            if training:
                optimizer.zero_grad(set_to_none=True)
            with torch.amp.autocast(device_type=device.type, enabled=device.type == "cuda"):
                logits = model(images)
                loss = criterion(logits, masks)
            if training:
                scaler.scale(loss).backward()
                scaler.unscale_(optimizer)
                torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
                scaler.step(optimizer)
                scaler.update()
            batch = images.shape[0]
            aggregate["loss"] += float(loss.item()) * batch
            aggregate["samples"] += batch
            for key, value in metrics(logits.detach(), masks).items():
                aggregate[key] += value
    return summarize(aggregate)


def transforms(settings: Settings):
    normalize = A.Normalize(mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225))
    train = A.Compose(
        [
            A.HorizontalFlip(p=0.5),
            A.ShiftScaleRotate(shift_limit=0.02, scale_limit=0.08, rotate_limit=7, border_mode=cv2.BORDER_REFLECT_101, p=0.5),
            A.RandomBrightnessContrast(brightness_limit=0.12, contrast_limit=0.12, p=0.35),
            A.Resize(settings.height, settings.width),
            normalize,
        ]
    )
    valid = A.Compose([A.Resize(settings.height, settings.width), normalize])
    return train, valid


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--data", type=Path, required=True)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--epochs", type=int, default=80)
    parser.add_argument("--batch-size", type=int, default=4)
    parser.add_argument("--workers", type=int, default=2)
    args = parser.parse_args()
    settings = Settings(epochs=args.epochs, batch_size=args.batch_size, workers=args.workers)
    seed_everything(settings.seed)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    if device.type != "cuda":
        raise RuntimeError("CUDA GPU is required for this training run.")
    torch.backends.cudnn.benchmark = True
    run_dir = args.run_dir
    checkpoint_dir = run_dir / "checkpoints"
    checkpoint_dir.mkdir(parents=True, exist_ok=True)
    (run_dir / "settings.json").write_text(json.dumps(asdict(settings), indent=2), encoding="utf-8")

    train_transform, valid_transform = transforms(settings)
    datasets = {
        "train": CalculusDataset(args.data / "images" / "train", args.data / "masks" / "train", train_transform),
        "val": CalculusDataset(args.data / "images" / "val", args.data / "masks" / "val", valid_transform),
        "test": CalculusDataset(args.data / "images" / "test", args.data / "masks" / "test", valid_transform),
    }
    loaders = {
        split: DataLoader(dataset, batch_size=settings.batch_size, shuffle=split == "train", num_workers=settings.workers,
                          pin_memory=True, persistent_workers=settings.workers > 0)
        for split, dataset in datasets.items()
    }
    model = smp.UnetPlusPlus(encoder_name="resnet34", encoder_weights="imagenet", in_channels=3, classes=1, activation=None).to(device)
    criterion = DiceBCELoss()
    optimizer = torch.optim.AdamW(model.parameters(), lr=settings.learning_rate, weight_decay=settings.weight_decay)
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(optimizer, T_max=settings.epochs, eta_min=1e-6)
    scaler = torch.amp.GradScaler("cuda", enabled=True)

    history: list[dict[str, float]] = []
    best_dice, stale = -1.0, 0
    for epoch in range(1, settings.epochs + 1):
        train_metrics = run_epoch(model, loaders["train"], criterion, optimizer, scaler, device, training=True)
        val_metrics = run_epoch(model, loaders["val"], criterion, optimizer, scaler, device, training=False)
        scheduler.step()
        row = {"epoch": epoch, "lr": optimizer.param_groups[0]["lr"], **{f"train_{k}": v for k, v in train_metrics.items()}, **{f"val_{k}": v for k, v in val_metrics.items()}}
        history.append(row)
        with (run_dir / "history.csv").open("w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=history[0].keys())
            writer.writeheader(); writer.writerows(history)
        print(f"epoch={epoch:03d} train_dice={train_metrics['dice']:.4f} val_dice={val_metrics['dice']:.4f} val_iou={val_metrics['iou']:.4f} val_recall={val_metrics['recall']:.4f}", flush=True)
        if val_metrics["dice"] > best_dice:
            best_dice, stale = val_metrics["dice"], 0
            torch.save({"model_state": model.state_dict(), "settings": asdict(settings), "best_val_dice": best_dice, "epoch": epoch}, checkpoint_dir / "best.pt")
        else:
            stale += 1
            if stale >= settings.patience:
                print(f"early_stop epoch={epoch} best_val_dice={best_dice:.4f}", flush=True)
                break

    checkpoint = torch.load(checkpoint_dir / "best.pt", map_location=device, weights_only=False)
    model.load_state_dict(checkpoint["model_state"])
    test_metrics = run_epoch(model, loaders["test"], criterion, optimizer=None, scaler=scaler, device=device, training=False)
    results = {"best_epoch": checkpoint["epoch"], "best_val_dice": checkpoint["best_val_dice"], "test": test_metrics, "device": torch.cuda.get_device_name(0)}
    (run_dir / "test_metrics.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
    print("FINAL " + json.dumps(results), flush=True)


if __name__ == "__main__":
    main()
