#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/node/redi-multi"
echo "[build] go build..."
go build -trimpath -o bin/redi-multi main.go
cd "$ROOT/node"
echo "[pack] redi-multi.tar.gz"
tar czf redi-multi.tar.gz \
  --exclude='redi-multi/.git' \
  redi-multi
ls -lh redi-multi.tar.gz
