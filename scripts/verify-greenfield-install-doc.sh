#!/usr/bin/env bash
# GREENFIELD-INSTALL 与绿场路径表述（防文档漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/GREENFIELD-INSTALL.md"

fail() {
  echo "verify-greenfield-install-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing GREENFIELD-INSTALL.md"

grep -qF 'DEPLOY-TUTORIAL-CENTOS7.md' "$DOC" || fail "GREENFIELD must link step tutorial"
grep -qF 'redi_admin.sql' "$DOC" || fail "GREENFIELD must document redi_admin.sql"
grep -qF 'schedule:run' "$DOC" || fail "GREENFIELD must warn against schedule:run"
grep -qF 'OPS-RELEASE-UPDATE.md' "$DOC" || fail "GREENFIELD must reference OPS-RELEASE-UPDATE for post-install tar"

echo "verify-greenfield-install-doc: OK"
