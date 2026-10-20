#!/usr/bin/env bash
# 静态校验真机验收清单与 Cron 模板门禁表述一致（防文档漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/ACCEPTANCE-CENTOS7-DEPLOY.md"

fail() {
  echo "verify-acceptance-centos7-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing ACCEPTANCE-CENTOS7-DEPLOY.md"

for needle in \
  'redi:ops-webhook' \
  '--task=redi:renew-certificates' \
  '--task=redi:prune-acme-challenges' \
  '--task=redi:health' \
  'schedule:run' \
  'master/master' \
  'grant-nodes-from-vars' \
  'redi_node' \
  '--max-time=3600'; do
  grep -qF -- "$needle" "$DOC" || fail "ACCEPTANCE doc missing: ${needle}"
done

echo "verify-acceptance-centos7-doc: OK"
