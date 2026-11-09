# 绿场安装说明

**全新环境**：按 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) 部署，数据库 **一次导入** `db/redi_admin.sql` 即可；`deploy-master.sh` 仍会执行 `migrate --force`，导入后通常无待执行迁移。详细脚本说明见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)。**绿场首装可跳过** [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)（仅在环境已在跑、需跟上新 tar 时阅读）。

## 相关文档

| 文档 | 用途 |
|------|------|
| [DEPLOY.md](../DEPLOY.md) | 部署索引与流量模型摘要 |
| [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) | **从零分步教程（推荐）** |
| [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md) | `vars.env` 填空示例 |
| [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) | 真机逐步验收 |
| [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) | 边缘池 F1–F8 |
| [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) | 一页上线打勾 |
| [docs/README.md](README.md) | 完整索引（含日常运营文档） |

## 数据库：一次导入

| 步骤 | 命令 / 动作 | 内容 |
|------|-------------|------|
| 1 建库 | `db/mysql_setup.example.sql` 或 `deploy-master.sh` | 创建 `redi_admin` 库与 `redi_admin` 应用账号 |
| 2 导入 | **`mysql … redi_admin < db/redi_admin.sql`**（空库时 `deploy-master.sh` 会自动导入） | Dcat 菜单/权限、业务表、默认超管 `admin`、**`redi_node_bump_skip`**、`migrations` 种子与当前发布包对齐 |

**不要**对空库只跑 `php artisan migrate` 而不导入 `redi_admin.sql`（会缺菜单与默认管理员）。

**验证（导入后）：**

```bash
mysql -u redi_admin -p redi_admin -e \
  "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'"
```

CentOS 的 `deploy-master.sh` 在 `migrate` 之后会检查该过程是否存在。

## 主控首次登录

- 默认账号：`admin` / `Redi301@Install2026`。
- 仍为默认密码时后台会 **强制改密**（`auth/setting`）；演示环境可在 `.env` 设 `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false`。
- 界面语言：简 / 繁 / 英，见 [docs/I18N.md](I18N.md)、[CONSOLE.md](CONSOLE.md)。

## 节点要点

- 发布包 `redi-multi.tar.gz`：静态 **linux/amd64** 二进制；**lagran 默认开启**；首次从 `config.json.example` 复制配置。
- `deploy-node.sh` 会 `setcap cap_net_admin`；生产 `REDI_MASTER_URL` 须 **https://**（lab 才允许 `REDI_MASTER_INSECURE=1` + `http://`）。
- 跳转策略经访客域名 **80/443** 上的 `/api.js` 从 MySQL 拉取，见 [node/README.md](../node/README.md)。

## Cron 与运维 webhook

`deploy-master.sh` 写入 `/etc/cron.d/redi-renew`：**03:10** 证书续期、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`（失败行带 `|| redi:ops-webhook`）。配置 `REDI_HEALTH_WEBHOOK_URL` 见 [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)、[PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)。**勿**配置 `php artisan schedule:run`。

## 节点授权与多 IP

MySQL 账号为 **`redi_node`** 时：在主控 `vars.env` 维护 `NODE_IPS`，执行 `grant-nodes-from-vars.sh` 或重跑 `deploy-master.sh`；单 IP 可用 `grant-node-mysql.sh`（仅 `EXECUTE` 该存储过程）。

更新节点二进制后须在发版机 `./scripts/build-node-tar.sh` 并在各节点重跑 `deploy-node.sh`（或替换 `bin/redi-multi` 后 `systemctl restart redi-multi`）。
