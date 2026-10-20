#!/usr/bin/env bash
# dev-preflight 中执行的 verify-*.sh 须在 .github/workflows/ci.yml 出现（防 CI 漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFLIGHT="${REPO_ROOT}/scripts/dev-preflight.sh"
CI="${REPO_ROOT}/.github/workflows/ci.yml"

fail() {
  echo "verify-ci-preflight-parity: $*" >&2
  exit 1
}

[[ -f "$PREFLIGHT" ]] || fail "missing dev-preflight.sh"
[[ -f "$CI" ]] || fail "missing ci.yml"

ci_body="$(<"$CI")"

while IFS= read -r script; do
  [[ -n "$script" ]] || continue
  base="$(basename "$script")"
  if [[ "$ci_body" != *"$base"* ]]; then
    fail "ci.yml must reference ${base} (run from dev-preflight)"
  fi
done < <(grep -oE 'scripts/verify-[a-z0-9-]+\.sh' "$PREFLIGHT" | sort -u)

echo "verify-ci-preflight-parity: OK"
