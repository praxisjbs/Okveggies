#!/usr/bin/env bash
# =============================================================================
# scripts/deploy.sh
# OK Veggies. Runs on the cPanel server after the new files are in place (the
# GitHub Actions workflow ships them, then calls this). The server has no
# Composer and no Node, so vendor/ and the built assets are already committed.
# This script applies pending migrations and runs the smoke tests.
# =============================================================================
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_ROOT"
echo "[deploy] app root: $APP_ROOT"

# 1. Back up first (database + uploads).
if [ -x scripts/backup.sh ]; then
  echo "[deploy] backing up..."
  bash scripts/backup.sh || echo "[deploy] backup step reported a problem, continuing."
fi

# 2. Apply pending migrations.
echo "[deploy] running migrations..."
php scripts/migrate.php

# 3. Smoke test the live site.
if [ -x scripts/verify.sh ]; then
  echo "[deploy] verifying..."
  bash scripts/verify.sh || { echo "[deploy] verify FAILED"; exit 1; }
fi

echo "[deploy] done."
