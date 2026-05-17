#!/usr/bin/env bash
set -euo pipefail

if [ -f frontend/package.json ]; then
  echo "==> Installing frontend dependencies (monorepo root)"
  npm install --prefix frontend
else
  echo "==> Installing frontend dependencies (frontend root directory)"
  npm install
fi
