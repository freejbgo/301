#!/usr/bin/env bash
# dev-preflight 中执行的 verify-*.sh 须在 ci.yml / release.yml 出现（防 CI 漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFLIGHT="${REPO_ROOT}/scripts/dev-preflight.sh"
CI="${REPO_ROOT}/.github/workflows/ci.yml"
REL="${REPO_ROOT}/.github/workflows/release.yml"

fail() {
  echo "verify-ci-preflight-parity: $*" >&2
  exit 1
}

[[ -f "$PREFLIGHT" ]] || fail "missing dev-preflight.sh"
[[ -f "$CI" ]] || fail "missing ci.yml"
[[ -f "$REL" ]] || fail "missing release.yml"

ci_body="$(<"$CI")"
rel_body="$(<"$REL")"

while IFS= read -r script; do
  [[ -n "$script" ]] || continue
  base="$(basename "$script")"
  if [[ "$ci_body" != *"$base"* ]]; then
    fail "ci.yml must reference ${base} (run from dev-preflight)"
  fi
  if [[ "$rel_body" != *"$base"* ]]; then
    fail "release.yml must reference ${base} (centos7-scripts parity)"
  fi
done < <(grep -oE 'scripts/verify-[a-z0-9-]+\.sh' "$PREFLIGHT" | sort -u)

for wf in ci.yml release.yml; do
  grep -q 'bash scripts/verify-redi-artisan-cron.sh' "${REPO_ROOT}/.github/workflows/${wf}" \
    || fail "${wf} must execute verify-redi-artisan-cron.sh (master-phpunit parity)"
done

echo "verify-ci-preflight-parity: OK"
