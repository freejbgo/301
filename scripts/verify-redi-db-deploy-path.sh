#!/usr/bin/env bash
# 验证与 deploy-master.sh 相同的建库 collation + redi_admin.sql 导入后，节点存储过程可调用。
# 在开发机或 CI 上通过 Docker 启动临时 MariaDB 10.6，不依赖本机已安装的 mysqld。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SQL="${REPO_ROOT}/db/redi_admin.sql"
[[ -f "$SQL" ]] || { echo "缺少 $SQL" >&2; exit 1; }

if ! command -v docker >/dev/null 2>&1; then
  echo "跳过 verify-redi-db-deploy-path：未安装 docker" >&2
  exit 0
fi

cid=$(docker run -d --rm -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:10.6)
trap 'docker rm -f "$cid" >/dev/null 2>&1 || true' EXIT

ready=0
for _ in $(seq 1 60); do
  if docker exec "$cid" mariadb-admin ping --silent >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" == 1 ]] || { echo "MariaDB 容器未就绪"; docker logs "$cid" 2>&1 | tail -20; exit 1; }

docker exec -i "$cid" mariadb -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS redi_admin
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL

docker exec -i "$cid" mariadb -uroot redi_admin < "$SQL"
docker exec "$cid" mariadb -uroot redi_admin -e "CALL redi_node_bump_skip('__ci_smoke__');" >/dev/null

echo "verify-redi-db-deploy-path: OK（unicode_ci 建库 + CALL redi_node_bump_skip）"
