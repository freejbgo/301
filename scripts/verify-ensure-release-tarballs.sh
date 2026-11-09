#!/usr/bin/env bash
# REDI_ALLOW_ON_SERVER_TAR_BUILD 时，构建产物须复制到 vars 配置的 REDI_*_TAR 路径。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENSURE="${REPO_ROOT}/scripts/ensure-release-tarballs.sh"

fail() {
  echo "verify-ensure-release-tarballs: $*" >&2
  exit 1
}

[[ -f "$ENSURE" ]] || fail "missing ensure-release-tarballs.sh"

grep -q 'redi_stage_built_tar' "$ENSURE" || fail "ensure must stage built tar to custom path"
grep -q 'master/master.tar.gz' "$ENSURE" || fail "ensure must reference default master.tar.gz artifact"
grep -q 'node/redi-multi.tar.gz' "$ENSURE" || fail "ensure must reference default node tar artifact"
grep -q 'REDI_ALLOW_ON_SERVER_TAR_BUILD' "$ENSURE" || fail "ensure must gate on-server build"

echo "verify-ensure-release-tarballs: OK"
