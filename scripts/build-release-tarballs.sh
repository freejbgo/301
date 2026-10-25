#!/usr/bin/env bash
# 在开发机一次性打出主控 + 节点发布包（不依赖 GitHub Actions / Releases）。
# 产物：master/master.tar.gz、node/redi-multi.tar.gz（已 gitignore，勿提交）。
#
# 建议先：bash scripts/dev-preflight.sh
# 部署：scp 到服务器后在 vars.env 设置 REDI_MASTER_TAR / REDI_NODE_TAR，见 docs/RELEASE-PACKAGES.md
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> 节点 redi-multi.tar.gz（linux/amd64 静态二进制）"
bash scripts/build-node-tar.sh

echo "==> 主控 master.tar.gz（composer --no-dev）"
APP="${ROOT}/master/master"
if [[ ! -f "${APP}/vendor/autoload.php" ]]; then
  echo "安装主控依赖…"
  (cd "$APP" && composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader)
fi
bash scripts/build-master-tar.sh

echo ""
echo "完成。上传机房示例："
echo "  scp master/master.tar.gz node/redi-multi.tar.gz root@<主机>:/tmp/"
echo "  # vars.env: REDI_MASTER_TAR=/tmp/master.tar.gz  REDI_NODE_TAR=/tmp/redi-multi.tar.gz"
echo "详见 docs/RELEASE-PACKAGES.md"
