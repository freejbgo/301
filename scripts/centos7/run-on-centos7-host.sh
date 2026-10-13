#!/usr/bin/env bash
# 在 Ubuntu 等宿主机上用 podman 启动 CentOS 7 容器并跑 container-integration-test.sh。
# 使用 --network host，避免部分环境下 podman 桥接网无外网导致 yum 失败。
# 用法: sudo bash scripts/centos7/run-on-centos7-host.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
C7=redi-c7-smoke
# 与生产常用的 CentOS 7.9 对齐（7.9.2009 为 7 系列最终小版本）
IMAGE=quay.io/centos/centos:7.9.2009

if ! command -v podman >/dev/null; then
  echo "需要 podman。Ubuntu: sudo apt-get install -y podman"
  exit 1
fi

podman stop -t 2 "$C7" 2>/dev/null || true
podman rm -f "$C7" 2>/dev/null || true

echo "启动 CentOS 7.9 容器（${IMAGE}，host 网络，privileged 便于 MariaDB 冒烟）..."
podman run -d --name "$C7" --hostname "$C7" --network host --privileged \
  -v "$ROOT:/workspace:rw" \
  "$IMAGE" tail -f /dev/null

MASTER_IP="${REDI_SMOKE_MASTER_IP:-127.0.0.1}"
echo "冒烟使用 MASTER_IP=${MASTER_IP}（可 export REDI_SMOKE_MASTER_IP 覆盖）"

echo "=== 容器内冒烟测试 ==="
podman exec "$C7" bash /workspace/scripts/centos7/container-integration-test.sh

echo "=== deploy-master 探测（无完整 systemd 时可能在 firewalld/systemctl 处失败） ==="
cat > /tmp/redi-vars-master.env <<EOF
MASTER_IP="${MASTER_IP}"
DB_PASS="SmokeDbPass2026"
MYSQL_ROOT_PASS="SmokeRootPass2026"
NODE_IPS=""
MASTER_INSTALL_ROOT="/opt/301"
MASTER_URL="http://${MASTER_IP}"
ADMIN_HTTPS="false"
EOF
podman cp /tmp/redi-vars-master.env "$C7:/tmp/redi-vars-master.env"
if podman exec "$C7" bash -lc 'cp /tmp/redi-vars-master.env /workspace/scripts/centos7/vars.env && bash /workspace/scripts/centos7/deploy-master.sh'; then
  echo "deploy-master 在容器内完整跑通"
else
  echo "deploy-master 未完整跑通（容器内常见：systemd/firewalld）。见宿主机 journal 或重定向日志。"
fi

cp /tmp/redi-vars-master.env "$ROOT/scripts/centos7/.container-vars.env"

echo "完成。集成日志: 容器内 /tmp/redi-c7-integration.log"
echo "保留容器 $C7 便于调试；删除: sudo podman rm -f $C7"
