#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/opt/tooth-backend"

cd "$APP_DIR"
docker compose up -d --build
docker compose ps
