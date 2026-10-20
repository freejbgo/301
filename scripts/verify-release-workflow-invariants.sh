#!/usr/bin/env bash
# release.yml 与 build-*-tar / 冒烟门禁约定（防 CI 漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REL="${REPO_ROOT}/.github/workflows/release.yml"
MASTER_TAR="${REPO_ROOT}/scripts/build-master-tar.sh"
NODE_TAR="${REPO_ROOT}/scripts/build-node-tar.sh"

fail() {
  echo "verify-release-workflow-invariants: $*" >&2
  exit 1
}

for f in "$REL" "$MASTER_TAR" "$NODE_TAR"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'container-integration-test.sh' "$REL" || fail "release must run CentOS container smoke"
grep -q 'composer audit' "$REL" || fail "release publish must run composer audit"
grep -q 'master/vendor/autoload.php' "$REL" || fail "release must verify master tar contents"
grep -q 'redi-multi/bin/redi-multi' "$REL" || fail "release must verify node tar contents"
grep -q 'verify-redi-node-procedure-sync.php' "$REL" || fail "release centos7-scripts must sync procedure"
grep -q 'CGO_ENABLED=0' "$NODE_TAR" || fail "node tar build must be static CGO_ENABLED=0"
grep -q 'master/.env' "$MASTER_TAR" || fail "master tar must exclude .env"
grep -q 'config.json.example' "$NODE_TAR" || fail "node pack must document config.json.example"

echo "verify-release-workflow-invariants: OK"
