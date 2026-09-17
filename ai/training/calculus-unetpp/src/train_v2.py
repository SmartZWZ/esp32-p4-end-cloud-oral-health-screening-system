"""Stabilized v2 training for binary dental-calculus segmentation."""

from __future__ import annotations

import argparse
import csv
import json
import math
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
from torch.utils.data import BatchSampler, DataLoader, Dataset, Sampler


@dataclass
class Settings:
    width: int = 672
    height: int = 448
    batch_size: int = 4
    positive_per_batch: int = 2
    epochs: int = 50
    patience: int = 10
    learning_rate: float = 1e-4
    weight_decay: float = 1e-5
    seed: int = 42
    workers: int = 2
    lesion_crop_probability: float = 0.55


def seed_everything(seed: int) -> None:
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)


def imread_unicode(path: Path, flag: int) -> np.ndarray:
    decoded = cv2.imdecode(np.fromfile(path, dtype=np.uint8), flag)
    if decoded is None:
        raise RuntimeError(f"Cannot read {path}")
    return decoded


class CalculusDataset(Dataset):
    def __init__(self, image_dir: Path, mask_dir: Path, transform: A.Compose, training: bool, crop_probability: float = 0.0):
        self.images = sorted(image_dir.glob("*.jpg"))
        self.mask_dir = mask_dir
        self.transform = transform
        self.training = training
        self.crop_probability = crop_probability
        if not self.images:
            raise RuntimeError(f"No images found in {image_dir}")
        self.targets: list[int] = []
        for image_path in self.images:
            mask = imread_unicode(mask_dir / f"{image_path.stem}.png", cv2.IMREAD_GRAYSCALE)
            self.targets.append(int(mask.max() > 127))

    def __len__(self) -> int:
        return len(self.images)

    @staticmethod
    def lesion_crop(image: np.ndarray, mask: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
        """Crop around a random lesion point while retaining sufficient tooth context."""
        height, width = mask.shape
        crop_width, crop_height = min(480, width), min(320, height)
        ys, xs = np.where(mask > 0)
        point = random.randrange(len(xs))
        center_x, center_y = int(xs[point]), int(ys[point])
        jitter_x = random.randint(-crop_width // 4, crop_width // 4)
        jitter_y = random.randint(-crop_height // 4, crop_height // 4)
        left = min(max(center_x + jitter_x - crop_width // 2, 0), width - crop_width)
        top = min(max(center_y + jitter_y - crop_height // 2, 0), height - crop_height)
        return image[top:top + crop_height, left:left + crop_width], mask[top:top + crop_height, left:left + crop_width]

    def __getitem__(self, index: int):
        image_path = self.images[index]
        image = cv2.cvtColor(imread_unicode(image_path, cv2.IMREAD_COLOR), cv2.COLOR_BGR2RGB)
        mask = (imread_unicode(self.mask_dir / f"{image_path.stem}.png", cv2.IMREAD_GRAYSCALE) > 127).astype(np.float32)
        if self.training and mask.any() and random.random() < self.crop_probability:
            image, mask = self.lesion_crop(image, mask)
        transformed = self.transform(image=image, mask=mask)
        tensor_image = torch.from_numpy(transformed["image"].transpose(2, 0, 1)).float()
        tensor_mask = torch.from_numpy(transformed["mask"]).unsqueeze(0).float()
        return tensor_image, tensor_mask, image_path.name


class BalancedBatchSampler(Sampler[list[int]]):
    """Each optimization batch has a stable positive/negative image mix."""
    def __init__(self, targets: list[int], batch_size: int, positive_per_batch: int, seed: int):
        self.positive = [index for index, target in enumerate(targets) if target == 1]
        self.negative = [index for index, target in enumerate(targets) if target == 0]
        self.batch_size = batch_size
        self.positive_per_batch = positive_per_batch
        self.negative_per_batch = batch_size - positive_per_batch
        self.seed = seed
        self.epoch = 0
        if not self.positive or not self.negative or self.negative_per_batch <= 0:
            raise RuntimeError("Balanced batches require both positive and negative samples.")

    def __iter__(self):
        rng = random.Random(self.seed + self.epoch)
        positive = self.positive.copy(); negative = self.negative.copy()
        rng.shuffle(positive); rng.shuffle(negative)
        batches = math.ceil(len(positive) / self.positive_per_batch)
        for batch_index in range(batches):
            batch = []
            for offset in range(self.positive_per_batch):
                batch.append(positive[(batch_index * self.positive_per_batch + offset) % len(positive)])
            for offset in range(self.negative_per_batch):
                batch.append(negative[(batch_index * self.negative_per_batch + offset) % len(negative)])
            rng.shuffle(batch)
            yield batch
        self.epoch += 1

    def __len__(self) -> int:
        return math.ceil(len(self.positive) / self.positive_per_batch)


class FocalTverskyFocalBCELoss(nn.Module):
    def __init__(self, alpha: float = 0.3, beta: float = 0.7, gamma: float = 1.33):
        super().__init__()
        self.alpha, self.beta, self.gamma = alpha, beta, gamma

    def forward(self, logits: torch.Tensor, targets: torch.Tensor) -> torch.Tensor:
        probabilities = torch.sigmoid(logits)
        dims = (1, 2, 3)
        true_positive = (probabilities * targets).sum(dims)
        false_positive = (probabilities * (1 - targets)).sum(dims)
        false_negative = ((1 - probabilities) * targets).sum(dims)
        tversky = (true_positive + 1.0) / (true_positive + self.alpha * false_positive + self.beta * false_negative + 1.0)
        focal_tversky = torch.pow(1 - tversky, self.gamma).mean()
        bce = F.binary_cross_entropy_with_logits(logits, targets, reduction="none")
        pt = targets * probabilities + (1 - targets) * (1 - probabilities)
        focal_bce = (torch.pow(1 - pt, 2.0) * bce).mean()
        return 0.7 * focal_tversky + 0.3 * focal_bce


def metric_counts(logits: torch.Tensor, targets: torch.Tensor, threshold: float) -> dict[str, int]:
    prediction = torch.sigmoid(logits) >= threshold
    truth = targets >= 0.5
    return {
        "tp": int((prediction & truth).sum().item()),
        "fp": int((prediction & ~truth).sum().item()),
        "fn": int((~prediction & truth).sum().item()),
    }


def metric_summary(counts: dict[str, float], loss: float, samples: int) -> dict[str, float]:
    tp, fp, fn = counts["tp"], counts["fp"], counts["fn"]
    return {
        "loss": loss / samples,
        "dice": (2 * tp + 1.0) / (2 * tp + fp + fn + 1.0),
        "iou": (tp + 1.0) / (tp + fp + fn + 1.0),
        "precision": (tp + 1.0) / (tp + fp + 1.0),
        "recall": (tp + 1.0) / (tp + fn + 1.0),
    }


def run_epoch(model, loader, criterion, optimizer, scaler, device, training: bool, threshold: float = 0.5) -> dict[str, float]:
    model.train(training)
    counts: dict[str, float] = {"tp": 0, "fp": 0, "fn": 0}
    accumulated_loss, samples = 0.0, 0
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
            batch_size = images.shape[0]
            accumulated_loss += float(loss.item()) * batch_size
            samples += batch_size
            values = metric_counts(logits.detach(), masks, threshold)
            for key, value in values.items():
                counts[key] += value
    return metric_summary(counts, accumulated_loss, samples)


def choose_threshold(model, loader, criterion, device, thresholds: list[float]) -> tuple[float, dict[str, float]]:
    results = {threshold: run_epoch(model, loader, criterion, None, None, device, training=False, threshold=threshold) for threshold in thresholds}
    threshold = max(results, key=lambda value: results[value]["dice"])
    return threshold, results[threshold]


def create_transforms(settings: Settings) -> tuple[A.Compose, A.Compose]:
    normalize = A.Normalize(mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225))
    train_transform = A.Compose([
        A.HorizontalFlip(p=0.5),
        A.Affine(scale=(0.94, 1.08), translate_percent=(-0.02, 0.02), rotate=(-7, 7), border_mode=cv2.BORDER_REFLECT_101, p=0.5),
        A.RandomBrightnessContrast(brightness_limit=0.10, contrast_limit=0.10, p=0.30),
        A.Resize(settings.height, settings.width), normalize,
    ])
    return train_transform, A.Compose([A.Resize(settings.height, settings.width), normalize])


def save_checkpoint(path: Path, model, optimizer, scheduler, scaler, epoch: int, best_dice: float, settings: Settings) -> None:
    torch.save({
        "model_state": model.state_dict(), "optimizer_state": optimizer.state_dict(), "scheduler_state": scheduler.state_dict(),
        "scaler_state": scaler.state_dict(), "epoch": epoch, "best_val_dice": best_dice, "settings": asdict(settings),
    }, path)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--data", type=Path, required=True)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--init", type=Path, required=True, help="Best v1 weight used as the v2 initialization.")
    parser.add_argument("--resume", type=Path, help="A v2 last.pt checkpoint for exact continuation.")
    args = parser.parse_args()
    settings = Settings()
    seed_everything(settings.seed)
    if not torch.cuda.is_available():
        raise RuntimeError("CUDA is required.")
    torch.backends.cudnn.benchmark = True
    device = torch.device("cuda")
    run_dir, checkpoint_dir = args.run_dir, args.run_dir / "checkpoints"
    checkpoint_dir.mkdir(parents=True, exist_ok=True)
    (run_dir / "settings.json").write_text(json.dumps(asdict(settings), indent=2), encoding="utf-8")

    train_transform, eval_transform = create_transforms(settings)
    train_data = CalculusDataset(args.data / "images" / "train", args.data / "masks" / "train", train_transform, training=True, crop_probability=settings.lesion_crop_probability)
    val_data = CalculusDataset(args.data / "images" / "val", args.data / "masks" / "val", eval_transform, training=False)
    test_data = CalculusDataset(args.data / "images" / "test", args.data / "masks" / "test", eval_transform, training=False)
    train_loader = DataLoader(train_data, batch_sampler=BalancedBatchSampler(train_data.targets, settings.batch_size, settings.positive_per_batch, settings.seed), num_workers=settings.workers, pin_memory=True, persistent_workers=True)
    val_loader = DataLoader(val_data, batch_size=settings.batch_size, shuffle=False, num_workers=settings.workers, pin_memory=True, persistent_workers=True)
    test_loader = DataLoader(test_data, batch_size=settings.batch_size, shuffle=False, num_workers=settings.workers, pin_memory=True, persistent_workers=True)

    model = smp.UnetPlusPlus(encoder_name="resnet34", encoder_weights="imagenet", in_channels=3, classes=1, activation=None).to(device)
    criterion = FocalTverskyFocalBCELoss()
    optimizer = torch.optim.AdamW(model.parameters(), lr=settings.learning_rate, weight_decay=settings.weight_decay)
    scheduler = torch.optim.lr_scheduler.ReduceLROnPlateau(optimizer, mode="max", factor=0.5, patience=3, threshold=0.002, min_lr=1e-6)
    scaler = torch.amp.GradScaler("cuda", enabled=True)
    start_epoch, best_dice, stale = 1, -1.0, 0
    if args.resume:
        checkpoint = torch.load(args.resume, map_location=device, weights_only=False)
        model.load_state_dict(checkpoint["model_state"]); optimizer.load_state_dict(checkpoint["optimizer_state"])
        scheduler.load_state_dict(checkpoint["scheduler_state"]); scaler.load_state_dict(checkpoint["scaler_state"])
        start_epoch, best_dice = checkpoint["epoch"] + 1, checkpoint["best_val_dice"]
    else:
        checkpoint = torch.load(args.init, map_location=device, weights_only=False)
        model.load_state_dict(checkpoint["model_state"])
    history: list[dict[str, float]] = []
    for epoch in range(start_epoch, settings.epochs + 1):
        train_metrics = run_epoch(model, train_loader, criterion, optimizer, scaler, device, training=True)
        val_metrics = run_epoch(model, val_loader, criterion, optimizer, scaler, device, training=False)
        scheduler.step(val_metrics["dice"])
        row = {"epoch": epoch, "lr": optimizer.param_groups[0]["lr"], **{f"train_{key}": value for key, value in train_metrics.items()}, **{f"val_{key}": value for key, value in val_metrics.items()}}
        history.append(row)
        with (run_dir / "history.csv").open("w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=history[0].keys()); writer.writeheader(); writer.writerows(history)
        save_checkpoint(checkpoint_dir / "last.pt", model, optimizer, scheduler, scaler, epoch, best_dice, settings)
        print(f"epoch={epoch:03d} lr={row['lr']:.1e} train_dice={train_metrics['dice']:.4f} val_dice={val_metrics['dice']:.4f} val_iou={val_metrics['iou']:.4f} val_precision={val_metrics['precision']:.4f} val_recall={val_metrics['recall']:.4f}", flush=True)
        if val_metrics["dice"] > best_dice:
            best_dice, stale = val_metrics["dice"], 0
            save_checkpoint(checkpoint_dir / "best.pt", model, optimizer, scheduler, scaler, epoch, best_dice, settings)
        else:
            stale += 1
            if stale >= settings.patience:
                print(f"early_stop epoch={epoch} best_val_dice={best_dice:.4f}", flush=True)
                break

    best = torch.load(checkpoint_dir / "best.pt", map_location=device, weights_only=False)
    model.load_state_dict(best["model_state"])
    threshold, val_metrics = choose_threshold(model, val_loader, criterion, device, [0.20, 0.30, 0.40, 0.50, 0.60, 0.70])
    test_metrics = run_epoch(model, test_loader, criterion, None, None, device, training=False, threshold=threshold)
    result = {"best_epoch": best["epoch"], "best_val_dice": best["best_val_dice"], "threshold": threshold, "val_at_threshold": val_metrics, "test": test_metrics, "device": torch.cuda.get_device_name(0)}
    (run_dir / "test_metrics.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
    print("FINAL " + json.dumps(result), flush=True)


if __name__ == "__main__":
    main()
