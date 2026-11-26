#!/usr/bin/env bash
# 安装文档仍写明一次导入 SQL、不要 schedule:run、换包另见 OPS。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/DEPLOY-centos7.md"
BEGINNER="${REPO_ROOT}/docs/DEPLOY-BEGINNER-CENTOS7.md"

fail() {
  echo "verify-greenfield-install-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing DEPLOY-centos7.md"
[[ -f "$BEGINNER" ]] || fail "missing DEPLOY-BEGINNER-CENTOS7.md"
grep -qF 'redi_admin.sql' "$DOC" || fail "DEPLOY-centos7 must document redi_admin.sql"
grep -qF 'schedule:run' "$DOC" || fail "DEPLOY-centos7 must warn against schedule:run"
grep -qF 'OPS-RELEASE-UPDATE.md' "$DOC" || fail "DEPLOY-centos7 must reference OPS-RELEASE-UPDATE for post-install tar"
grep -qF 'master.sh' "$BEGINNER" || fail "beginner doc must document master.sh"
grep -qF 'node.sh' "$BEGINNER" || fail "beginner doc must document node.sh"
grep -qF 'Redi301@Install2026' "$BEGINNER" || fail "beginner doc must document the initial admin password"

echo "verify-greenfield-install-doc: OK"
