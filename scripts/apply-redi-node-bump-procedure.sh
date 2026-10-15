#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SQL="${ROOT}/db/redi_node_bump_skip_procedure.sql"
[[ -f "$SQL" ]] || { echo "找不到 $SQL" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || { echo "请 sudo 执行" >&2; exit 1; }

if [[ -f /root/.redi-mysql.cnf ]]; then
  mysql --defaults-extra-file=/root/.redi-mysql.cnf redi_admin < "$SQL"
else
  mysql -u root redi_admin < "$SQL"
fi
