#!/usr/bin/env bash
# 编出 CentOS 7 可运行的节点发布包 node/redi-multi.tar.gz（linux/amd64）。
# CGO_ENABLED=0 静态链接，不依赖 GLIBC_2.32 / 2.34，内核小版本不同也不用重编。
# 先写出 node/redi-multi.tar.gz（不提交）。本脚本结束时复制到 pkg/redi-multi.tar.gz，要提交的是 pkg/ 里这一份。
# 打包前建议 bash scripts/dev-preflight.sh（见 docs/DEVELOPMENT.md）。
# 节点跳转/限速/Agent 逻辑均在此二进制；更新 node/redi-multi 后须重新打包并部署各节点。
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
if [[ ! -f config.json ]]; then
  cp config.json.example config.json
fi
cd "$ROOT/node"
echo "[pack] redi-multi.tar.gz"
# 不把本机 config.json 打进包：线上应从 config.json.example 复制（deploy-node.sh 会处理）
tar czf redi-multi.tar.gz \
  --exclude='redi-multi/.git' \
  --exclude='redi-multi/config.json' \
  redi-multi
ls -lh redi-multi.tar.gz
mkdir -p "$ROOT/pkg"
cp -f "$ROOT/node/redi-multi.tar.gz" "$ROOT/pkg/redi-multi.tar.gz"
echo "已复制到 pkg/redi-multi.tar.gz"
