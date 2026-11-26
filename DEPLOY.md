# 部署说明（索引）

面向 **被干扰、访问不稳的客户域名**：客户 CNAME 到你们的边缘池，由节点提供跳转页，并默认开启 **lagran** 调整出站 TCP 窗口。控制台操作见 [docs/CONSOLE.md](docs/CONSOLE.md)。

**生产仅支持 CentOS 7.x 裸机**（`yum` + 发布 tar + `deploy-*.sh`，**不用 Docker**）。请把本仓库当作**从未上线过的全新产品**来装（见 [docs/GREENFIELD-PRODUCT.md](docs/GREENFIELD-PRODUCT.md)）：数据库以 **一次导入** `db/redi_admin.sql` 为准，不按历史迁移理解步骤。

## 从零上线（推荐路径）

| 步骤 | 文档 |
|------|------|
| 0 | **主控一条命令**：[master.sh](master.sh)，说明见 [docs/DEPLOY-BEGINNER-CENTOS7.md](docs/DEPLOY-BEGINNER-CENTOS7.md) |
| 1 | 发版机：`bash scripts/dev-preflight.sh` → `bash scripts/build-release-tarballs.sh`（见 [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md)） |
| 2 | **分步教程**：[docs/DEPLOY-TUTORIAL-CENTOS7.md](docs/DEPLOY-TUTORIAL-CENTOS7.md) |
| 3 | 脚本与可选配置：[DEPLOY-centos7.md](DEPLOY-centos7.md)；生产 `vars`：[scripts/centos7/vars.production.example.env](scripts/centos7/vars.production.example.env) |
| 4 | 绿场数据库与首登：[docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md) |
| 5 | **签收**：[docs/LAUNCH-CHECKLIST.md](docs/LAUNCH-CHECKLIST.md) |
| 6 | 可选深挖：[docs/ACCEPTANCE.md](docs/ACCEPTANCE.md)（§A 安装逐项、§B F1–F8） |

**生产安全：** `vars.env` 里示例的 `REDI_MASTER_INSECURE=1` 与 `http://` **仅限联调**；对外上线前主控须 HTTPS（`ADMIN_HTTPS=true`），节点 `REDI_MASTER_URL` 必须为 `https://`（见 [SECURITY.md](SECURITY.md)）。

完整文档索引：[docs/README.md](docs/README.md)。

## 流量模型（摘要）

1. 客户域名 **CNAME** 到 `{token}.平台主域名`（同租户共用）。
2. `{token}.平台主域名` **CNAME** 到 `pool.平台主域名`。
3. `pool` 多条 **A** 指向所有**启用**节点的公网 IPv4（同一台机器多 IP 写在一条节点的「其他公网 IP」，不要每个 IP 建一条节点）。

证书由主控 ACME（HTTP-01）申请并下发到启用节点；访客可能落到池中任意节点。

## 目录与安装位置

| 场景 | 主控程序目录 | 网站根 |
|------|--------------|--------|
| **生产（CentOS deploy）** | `/opt/301/master` | `/opt/301/master/public` |
| 克隆仓库调试 | `master/master` | `master/master/public` |

节点生产目录：`/opt/redi-multi/redi-multi`（二进制 `bin/redi-multi`，配置 `config.json` + `env.sh`）。

## 端口与账号（摘要）

| 机器 | 对公网 | 不对公网 |
|------|--------|----------|
| 主控 | 22、80、443 | 3306 |
| 节点 | 22、80、443 | 3306、8080（默认仅 loopback） |

- 初始后台：`admin` / `Redi301@Install2026`（登录后强制改密，见 [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md)）。
- 节点 MySQL 账号：**`redi_node`**（仅 `EXECUTE` 存储过程 `redi_node_bump_skip`），由 `grant-node-mysql.sh` 或 `grant-nodes-from-vars.sh` 授权。

## 上线后换发布包

仅当环境**已在跑**、需要跟上新 tar 时，见 [docs/OPS-RELEASE-UPDATE.md](docs/OPS-RELEASE-UPDATE.md)（绿场首装不必阅读）。

## 开发机手工装库（非生产）

在 CentOS 7 机房**不要**用克隆目录代替 tar + `deploy-master.sh`。若仅在开发机手搓 Nginx/PHP 调试，见 [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) §「开发机手工部署」。

## 不要这样做

- 不要把客户域名 **A** 到单台节点 IP。
- 不要 `iptables -F` 或把 MySQL 3306 开放给 `0.0.0.0/0`。
- 不要把密码、Token 写入仓库或 Go 源码。
