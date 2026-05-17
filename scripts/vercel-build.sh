#!/usr/bin/env bash
set -euo pipefail

if [ -f frontend/package.json ]; then
  echo "==> Building frontend (monorepo root)"
  npm run build --prefix frontend
else
  echo "==> Building frontend (frontend root directory)"
  npm run build
fi
