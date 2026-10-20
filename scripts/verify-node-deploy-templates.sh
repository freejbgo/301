#!/usr/bin/env bash
# 静态校验 CentOS 7 节点部署模板（deploy-node、vars 示例、lagran 运维脚本）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

fail() {
  echo "verify-node-deploy-templates: $*" >&2
  exit 1
}

DEPLOY="${REPO_ROOT}/scripts/centos7/deploy-node.sh"
VARS="${REPO_ROOT}/scripts/centos7/vars.example.env"
LIB="${REPO_ROOT}/scripts/centos7/lib.sh"

[[ -f "$DEPLOY" ]] || fail "missing deploy-node.sh"
[[ -f "$VARS" ]] || fail "missing vars.example.env"
[[ -f "$LIB" ]] || fail "missing lib.sh"

# deploy-node：生产陷阱（Agent HTTPS、静态二进制、DB 冒烟、lagran cap）
grep -q 'redi-multi\.service' "$DEPLOY" || fail "deploy-node: missing redi-multi systemd unit"
grep -q 'EnvironmentFile=\${APP}/env.sh' "$DEPLOY" || fail "deploy-node: systemd must load env.sh"
grep -q 'setcap cap_net_admin' "$DEPLOY" || fail "deploy-node: missing setcap for lagran"
grep -q 'redi_ensure_lagran_enabled' "$DEPLOY" || fail "deploy-node: must enable lagran in config.json"
grep -q 'redi_node_bump_skip' "$DEPLOY" || fail "deploy-node: missing DB smoke CALL"
grep -q 'REDI_DB_USER=redi_node' "$DEPLOY" || fail "deploy-node: env.sh must use redi_node (not redi_admin)"
grep -qE 'ldd.*bin/redi-multi' "$DEPLOY" || fail "deploy-node: must verify static binary (ldd check)"
grep -q 'GLIBC' "$DEPLOY" || fail "deploy-node: must reject dynamic libc on CentOS 7"
grep -q 'REDI_MASTER_INSECURE' "$DEPLOY" || fail "deploy-node: must document REDI_MASTER_INSECURE lab path"
grep -q '必须使用 https://' "$DEPLOY" || fail "deploy-node: must require https for REDI_MASTER_URL by default"

# vars 示例：节点/主控关键变量齐全
for key in NODE_INSTALL_ROOT NODE_PUBLIC_IP DEFAULT_REDIRECT_URL REDI_MASTER_URL REDI_NODE_TOKEN; do
  grep -q "^${key}=" "$VARS" || fail "vars.example.env: missing ${key}"
done
grep -q 'REDI_MASTER_INSECURE' "$VARS" || fail "vars.example.env: missing REDI_MASTER_INSECURE hint"
grep -q 'NODE_IPS' "$VARS" || fail "vars.example.env: missing NODE_IPS"

# lagran 脚本与 lagran_ports.py 一致
for script in lagran-cleanup-iptables.sh lagran-diagnose.sh; do
  f="${REPO_ROOT}/scripts/centos7/${script}"
  [[ -f "$f" ]] || fail "missing ${script}"
  grep -q 'lagran_ports.py' "$f" || fail "${script}: must invoke lagran_ports.py"
  grep -q 'NFQUEUE' "$f" || fail "${script}: must reference NFQUEUE rules"
done

grep -q 'nfnetlink_queue' "${REPO_ROOT}/scripts/centos7/deploy-node.sh" \
  || fail "deploy-node: must attempt nfnetlink_queue for lagran"

grep -q 'redi_ensure_lagran_enabled' "$LIB" || fail "lib.sh: missing redi_ensure_lagran_enabled"

# env.example 与 deploy 写入的键对齐
ENV_EX="${REPO_ROOT}/node/redi-multi/env.example"
[[ -f "$ENV_EX" ]] || fail "missing node/redi-multi/env.example"
for key in REDI_DB_HOST REDI_DB_USER REDI_DB_PASSWORD REDI_DB_NAME REDI_MASTER_URL REDI_NODE_TOKEN REDI_LAGRAN_DISABLED; do
  grep -q "$key" "$ENV_EX" || fail "env.example: missing ${key}"
done
grep -q 'REDI_DB_PASSWORD=' "$DEPLOY" || fail "deploy-node: env.sh must set REDI_DB_PASSWORD"

echo "verify-node-deploy-templates: OK"
