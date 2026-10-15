# 在自购 Ubuntu VPS 上真机部署（推荐签收路径）

Cloud Agent 虚拟机可能 **environment unreachable**、磁盘重置、无 NFQUEUE/systemd，**不能**作为生产签收依据。请在 **Ubuntu 22.04 / 24.04 LTS VPS**（主控与节点分机或同机）按下列步骤操作。

## 1. 准备

- 一台主控 VPS；每台节点一台 VPS（或 lab 同机但节点须独占 80/443，勿与主控 Nginx 同占）。
- 安全组/防火墙：主控 22、80、443；3306 **仅**节点 IP。
- 仓库与发布包：clone 后 `composer install` + `scripts/build-master-tar.sh` / `scripts/build-node-tar.sh`，或使用 Release tar。

## 2. 主控

```bash
cp scripts/ubuntu/vars.example.env scripts/ubuntu/vars.env
# 填写 MASTER_IP、DB_PASS、NODE_IPS、MASTER_URL 等
chmod 600 scripts/ubuntu/vars.env
sudo bash scripts/ubuntu/deploy-master.sh
```

验收：`curl -sI http://127.0.0.1/admin` 为 200/302；`systemctl is-active nginx mariadb php*-fpm redi-queue`。

## 3. 节点

```bash
# vars.env 填 MASTER_IP、DB_PASS、REDI_MASTER_URL、REDI_NODE_TOKEN、NODE_PUBLIC_IP 等
sudo bash scripts/ubuntu/deploy-node.sh
```

验收：`systemctl is-active redi-multi`；`mysql -h 主控IP -u redi_node -p… -e "CALL redi_node_bump_skip('__smoke__')"`。

## 4. 同机 lab 仅脚本冒烟

主控+节点在同一台机器时，用 **`REDI_SMOKE_SINGLE_HOST=1`**（`run-smoke-acceptance.sh` 已内置）：节点监听 `127.0.0.1:18080`，关闭 lagran/TLS，避免与 Nginx 冲突。**生产节点仍须标准 80/443 + lagran。**

## 5. GitHub Actions 配额用尽

CI 红不影响脚本本身；请在 VPS 上执行上述步骤与 [ACCEPTANCE-UBUNTU-DEPLOY.md](ACCEPTANCE-UBUNTU-DEPLOY.md)、F1–F8。
