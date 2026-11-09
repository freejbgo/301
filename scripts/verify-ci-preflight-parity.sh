#!/usr/bin/env bash
# dev-preflight 中执行的 verify-*.sh 须在 ci.yml / release.yml 出现（防 CI 漂移）。
# 若 workflow 直接执行 dev-preflight.sh，视为覆盖其中全部 verify 门禁。
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

wf_uses_preflight() {
  local body="$1"
  [[ "$body" == *"dev-preflight.sh"* ]]
}

while IFS= read -r script; do
  [[ -n "$script" ]] || continue
  base="$(basename "$script")"
  if wf_uses_preflight "$ci_body"; then
    continue
  fi
  if [[ "$ci_body" != *"$base"* ]]; then
    fail "ci.yml must reference ${base} (run from dev-preflight)"
  fi
done < <(grep -oE 'scripts/verify-[a-z0-9-]+\.sh' "$PREFLIGHT" | sort -u)

while IFS= read -r script; do
  [[ -n "$script" ]] || continue
  base="$(basename "$script")"
  if wf_uses_preflight "$rel_body"; then
    continue
  fi
  if [[ "$rel_body" != *"$base"* ]]; then
    fail "release.yml must reference ${base} (centos7-scripts parity)"
  fi
done < <(grep -oE 'scripts/verify-[a-z0-9-]+\.sh' "$PREFLIGHT" | sort -u)

if ! wf_uses_preflight "$ci_body"; then
  fail 'ci.yml must run scripts/dev-preflight.sh or list each verify script'
fi
if ! wf_uses_preflight "$rel_body"; then
  fail 'release.yml must run scripts/dev-preflight.sh or list each verify script'
fi

for wf in ci.yml release.yml; do
  grep -q 'bash scripts/verify-redi-artisan-cron.sh' "${REPO_ROOT}/.github/workflows/${wf}" \
    || grep -q 'dev-preflight.sh' "${REPO_ROOT}/.github/workflows/${wf}" \
    || fail "${wf} must execute verify-redi-artisan-cron or dev-preflight"
done

echo "verify-ci-preflight-parity: OK"
