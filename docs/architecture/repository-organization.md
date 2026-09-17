# Repository Organization

This branch was rebuilt from the `2026-08-30` project snapshot.

## Git-managed content

- `firmware/`: ESP-IDF firmware, LVGL UI code and board support.
- `backend/`: FastAPI service plus the legacy PHP backend during migration.
- `web/`: current web app and the complete self-contained legacy deployment.
- `ai/`: training, inference, quantization, model cards and release metadata.
- `shared/`: protocol definitions, API contracts and JSON schemas.
- `docs/`: architecture, operation guides, reports and presentation material.
- `assets/`: diagrams, UI design exports and non-sensitive demo assets.
- `ops/`: Nginx, systemd, certificates and deployment configuration.

## External content

Raw datasets, patient images, training runs, checkpoints and large deployment
packages are intentionally excluded from Git. Their original archive
manifest is stored at `artifacts/snapshots/2026-08-30/archive-manifest.json`.

## Privacy

Clinical and dental images from `assets/demo`, model validation runs and
annotation datasets must not be published without documented consent and
de-identification.
