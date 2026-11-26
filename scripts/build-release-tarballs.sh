#!/usr/bin/env bash
# 在开发机一次性打出主控 + 节点发布包（不依赖 GitHub Actions / Releases）。
# 临时产物：master/master.tar.gz、node/redi-multi.tar.gz（已 gitignore）。
# 同时复制到 pkg/，这是仓库里给 master.sh 直接下载安装的包，要提交。
#
# 建议先：bash scripts/dev-preflight.sh
# 部署：一条命令见 docs/DEPLOY-BEGINNER-CENTOS7.md；手工 scp 与 REDI_*_TAR 见 DEPLOY-centos7.md。
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

mkdir -p "${ROOT}/pkg"
cp -f "${ROOT}/master/master.tar.gz" "${ROOT}/pkg/master.tar.gz"
cp -f "${ROOT}/node/redi-multi.tar.gz" "${ROOT}/pkg/redi-multi.tar.gz"
echo "==> 已更新 pkg/master.tar.gz 与 pkg/redi-multi.tar.gz（提交这两个文件）"

echo ""
echo "完成。要提交的是 pkg/master.tar.gz 与 pkg/redi-multi.tar.gz。"
echo "上传机房示例："
echo "  scp pkg/master.tar.gz pkg/redi-multi.tar.gz root@<主机>:/tmp/"
echo "  # vars.env: REDI_MASTER_TAR=/tmp/master.tar.gz  REDI_NODE_TAR=/tmp/redi-multi.tar.gz"
echo "下一步：docs/DEPLOY-BEGINNER-CENTOS7.md（一条命令）或 DEPLOY-centos7.md（手工 scp 与 deploy）"
