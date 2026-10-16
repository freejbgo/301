#!/usr/bin/env bash
# 打主控发布包 master/master.tar.gz。
# 包内带 composer install --no-dev 的 vendor，不带 .env，服务器上不用再跑 composer。
# 产物勿提交 git；发 v* tag 时 release.yml 在测试通过后会打相同包并上传 Release。
# 打包前建议 bash scripts/dev-preflight.sh（见 docs/AUDIT-CENTOS7-PRODUCTION.md §9）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT/master/master"
if [[ ! -f "$APP/vendor/autoload.php" ]]; then
  echo "缺少 vendor。请在 master/master 执行: composer install --no-dev --optimize-autoloader" >&2
  exit 1
fi
if [[ -d "$APP/vendor/phpunit" || -d "$APP/vendor/facade" ]]; then
  echo "vendor 里有开发依赖。请重新执行 composer install --no-dev" >&2
  exit 1
fi
if [[ ! -f "$APP/bootstrap/cache/packages.php" ]]; then
  echo "缺少 bootstrap/cache/packages.php。请先在 master/master 成功跑过 composer install" >&2
  exit 1
fi
cd "$ROOT/master"
echo "[pack] master.tar.gz"
tar czf master.tar.gz \
  --exclude='master/.env' \
  --exclude='master/.git' \
  --exclude='master/node_modules' \
  --exclude='master/storage/logs/*.log' \
  --exclude='master/storage/framework/sessions/*' \
  --exclude='master/storage/framework/views/*' \
  --exclude='master/storage/framework/cache/data/*' \
  master
# 不用 grep -q。pipefail 下它会提前结束，tar 或 printf 收到 SIGPIPE 后整条管道被当成失败。
list=$(tar tzf master.tar.gz)
case $'\n'"${list}"$'\n' in
  *$'\nmaster/.env\n'*)
    echo "发布包里出现了 .env，已中止" >&2
    exit 1
    ;;
esac
case $'\n'"${list}"$'\n' in
  *$'\nmaster/vendor/autoload.php\n'*) ;;
  *)
    echo "发布包里没有 vendor/autoload.php" >&2
    exit 1
    ;;
esac
case $'\n'"${list}"$'\n' in
  *$'\nmaster/bootstrap/cache/packages.php\n'*) ;;
  *)
    echo "发布包里没有 bootstrap/cache/packages.php" >&2
    exit 1
    ;;
esac
ls -lh master.tar.gz
