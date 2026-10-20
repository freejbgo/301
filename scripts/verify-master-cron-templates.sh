#!/usr/bin/env bash
# 静态校验主控 Cron / queue unit 模板（deploy-master、patch-cron、systemd 示例）。
# 防止误写 schedule:run、master/master 路径、缺 webhook 兜底或 queue 缺 --max-time。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

fail() {
  echo "verify-master-cron-templates: $*" >&2
  exit 1
}

# 五段式 cron 行（deploy-master / patch-cron 内 heredoc）
cron_lines_in() {
  local file="$1"
  grep -E '^[0-9*]+[[:space:]]+[0-9*]+[[:space:]]+[0-9*]+[[:space:]]+[0-9*]+[[:space:]]+[0-9*]+[[:space:]]+' "$file" || true
}

check_cron_script() {
  local file="$1"
  [[ -f "$file" ]] || fail "missing $file"
  local lines
  lines="$(cron_lines_in "$file")"
  [[ -n "$lines" ]] || fail "$file: no cron schedule lines found"

  local count=0
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    count=$((count + 1))
    [[ "$line" == *schedule:run* ]] && fail "$file: cron must not use schedule:run"
    [[ "$line" == *master/master* ]] && fail "$file: cron must not reference master/master path"
    [[ "$line" == *'|| /usr/bin/php artisan redi:ops-webhook'* ]] \
      || fail "$file: cron line missing ops-webhook fallback: ${line:0:80}..."
    if [[ "$line" == *'redi:renew-certificates'* ]]; then
      [[ "$line" == *'--task=redi:renew-certificates'* ]] \
        || fail "$file: renew cron must pass --task=redi:renew-certificates to ops-webhook"
    elif [[ "$line" == *'redi:prune-acme-challenges'* ]]; then
      [[ "$line" == *'--task=redi:prune-acme-challenges'* ]] \
        || fail "$file: prune cron must pass --task=redi:prune-acme-challenges to ops-webhook"
    elif [[ "$line" == *'redi:health --notify'* ]]; then
      [[ "$line" == *'--task=redi:health'* ]] \
        || fail "$file: health cron must pass --task=redi:health to ops-webhook"
    else
      fail "$file: unrecognized cron artisan command: ${line:0:80}..."
    fi
  done <<< "$lines"

  if [[ "$count" -lt 3 ]]; then
    fail "$file: expected at least 3 cron lines, got $count"
  fi

  for needle in redi:renew-certificates redi:prune-acme-challenges 'redi:health --notify'; do
    grep -q "$needle" "$file" || fail "$file: missing $needle in cron template"
  done
}

check_cron_script scripts/centos7/deploy-master.sh
check_cron_script scripts/patch-master-cron-ops-webhook.sh

for unit in scripts/centos7/deploy-master.sh scripts/systemd/redi-queue.service.example; do
  grep -q '\-\-max-time=3600' "$unit" || fail "$unit: queue:work must include --max-time=3600"
  grep -q '\-\-timeout=320' "$unit" || fail "$unit: queue:work should include --timeout=320"
done

grep -q 'cd \${APP}' scripts/centos7/deploy-master.sh \
  || grep -q 'cd ${APP}' scripts/centos7/deploy-master.sh \
  || fail "deploy-master: cron should cd to \${APP}"

echo "verify-master-cron-templates: OK"
