#!/usr/bin/env bash
set -euo pipefail

# Top-level start script delegates to backend/start.sh
if [ -f ./backend/start.sh ]; then
  echo "Delegating start to ./backend/start.sh"
  bash ./backend/start.sh
else
  echo "Error: ./backend/start.sh not found"
  exit 1
fi
