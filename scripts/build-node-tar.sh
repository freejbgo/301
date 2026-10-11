#!/usr/bin/env bash
# 编出 CentOS 7 可运行的节点发布包 node/redi-multi.tar.gz。
# CGO_ENABLED=0 静态链接，不依赖 GLIBC_2.32 / 2.34，内核小版本不同也不用重编。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/node/redi-multi"
echo "[build] 静态编译 bin/redi-multi"
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
  go build -mod=readonly -trimpath -ldflags '-s -w' -o bin/redi-multi .
if ldd bin/redi-multi 2>&1 | grep -q 'GLIBC\|libc.so'; then
  echo "二进制仍动态链接 libc，不能在 CentOS 7（glibc 2.17）上运行" >&2
  exit 1
fi
file bin/redi-multi
cd "$ROOT/node"
echo "[pack] redi-multi.tar.gz"
tar czf redi-multi.tar.gz \
  --exclude='redi-multi/.git' \
  redi-multi
ls -lh redi-multi.tar.gz
