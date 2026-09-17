#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SITE_ROOT="$(cd "$ROOT/.." && pwd)"
cd "$ROOT"

# 默认通过本机宝塔站点端口访问 PHP；如端口不同，可在守护进程环境变量中覆盖。
export CHIJING_BASE_URL="${CHIJING_BASE_URL:-http://127.0.0.1:667}"
export CHIJING_MODEL_PIPELINE="${CHIJING_MODEL_PIPELINE:-caries}"
export CHIJING_TORCH_THREADS="${CHIJING_TORCH_THREADS:-1}"
if [[ -z "${CHIJING_MODEL_SECRET:-}" ]]; then
  export CHIJING_MODEL_SECRET="$(sed -n "s/.*MODEL_CALLBACK_SECRET = '\([^']*\)'.*/\1/p" "$SITE_ROOT/api/config.php")"
fi
if [[ -z "$CHIJING_MODEL_SECRET" ]]; then
  echo "无法从 api/config.php 读取 MODEL_CALLBACK_SECRET。" >&2
  exit 1
fi

RUNTIME_PYTHON="$(dirname "$SITE_ROOT")/chijing_runtime/model_venv/bin/python"
PYTHON_BIN="${CHIJING_PYTHON:-$RUNTIME_PYTHON}"
if [[ ! -x "$PYTHON_BIN" ]]; then
  PYTHON_BIN="$ROOT/.venv/bin/python"
fi
if [[ ! -x "$PYTHON_BIN" ]]; then
  PYTHON_BIN="$(command -v python3)"
fi
exec "$PYTHON_BIN" worker.py "$@"
