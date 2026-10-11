#!/usr/bin/env bash
# 节点代码变更后请运行本脚本并提交 node/redi-multi.tar.gz，与 DEPLOY-centos7 解压包保持一致。
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
