# 绿场安装说明（无历史数据）

本仓库按 **全新部署（绿场）** 设计：你从未装过这套系统时，**不必**单独研究 Laravel 迁移编号；按 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 跑脚本并 **一次导入** `db/redi_admin.sql` 即可。日后跟新发布包见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)（与绿场首装无关）。

## 文档索引

| 文档 | 用途 |
|------|------|
| [DEPLOY.md](../DEPLOY.md) | 通用部署说明（手工步骤；生产一键脚本仅 CentOS 7） |
| [DEPLOY-centos7.md](../DEPLOY-centos7.md) | CentOS 7 一键 `deploy-master.sh` / `deploy-node.sh` |
| [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md) | `vars.env` 填 IP 示例 |
| [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) | 真机逐步验收 |
| [docs/CONSOLE.md](CONSOLE.md) | 后台操作 |
| [docs/PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) | 上线验收与 `redi:health` |
| [SECURITY.md](../SECURITY.md) | 密码、端口、节点权限 |
| [docs/AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §9 | 开发机发版前 `dev-preflight.sh` |
| [scripts/README.md](../scripts/README.md) | `verify-*` 门禁与 CentOS 辅助脚本索引 |

## 数据库：绿场一次导入即可

| 步骤 | 命令 / 动作 | 内容 |
|------|-------------|------|
| 1 建库 | `db/mysql_setup.example.sql` 或 `deploy-master.sh` | 创建 `redi_admin` 库与 `redi_admin` 应用账号 |
| 2 导入 | **`mysql … redi_admin < db/redi_admin.sql`**（`deploy-master.sh` 空库时会自动导入） | Dcat 菜单/权限、业务表、默认超管 `admin`、节点存储过程 **`redi_node_bump_skip`**、节点 IPv6 列；`migrations` 表已与当前发布包对齐 |

**绿场不需要**手工执行 `php artisan migrate`。`deploy-master.sh` 解压主控后仍会跑 **`migrate --force`**（导入 SQL 后一般为 **无待执行迁移**）；你只需确认存储过程存在即可。导入完成后节点即可 `CALL redi_node_bump_skip`。

**验证（主控本机，导入 SQL 后）：**

```bash
mysql -u redi_admin -p redi_admin -e \
  "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'"
```

CentOS 的 `deploy-master.sh` 在 `migrate` 之后会检查该过程是否存在。

**不要**只对空库跑 `migrate` 而不导入 `redi_admin.sql`（会缺菜单与默认管理员）。绿场请始终 **`redi_admin.sql` 一次导入**（或交给 `deploy-master.sh`）。

## 主控首次登录

- 默认账号：`admin` / `Redi301@Install2026`（见 [DEPLOY.md](../DEPLOY.md)）。
- 仍为默认密码时，后台会 **强制跳转改密**（`auth/setting`）。内网演示可在 `.env` 设 `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false`。

## 节点（被干扰域名场景）

- 发布包 `node/redi-multi.tar.gz`：**不含**本机 `config.json`，首次从 `config.json.example` 复制；二进制为 **linux/amd64** 静态链接；**lagran 默认开启**。本地 `scripts/build-*-tar.sh` 或打 **`v*` tag** 由 `release.yml` 生成（见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)）。
- `deploy-node.sh` 会 `setcap cap_net_admin`，并在应开启 lagran 时检查 capability 与日志。`REDI_MASTER_URL` 生产须 **https://**；主控尚无证书时 lab 可 `REDI_MASTER_INSECURE=1` + `http://`（见 `vars.example.env`）。
- 跳转配置通过访客域名上的 **`/api.js`** 从 MySQL 拉取（见 [node/README.md](../node/README.md)）。

## 首装之后若要换发布包

绿场 **首装完成** 后，一般只需按验收文档运营；**换 tar / 跟仓库新版本** 才需要 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)（含何时再跑 `migrate`）。节点 MySQL 账号为 **`redi_node`** 时，在主控更新 `vars.env` 的 `NODE_IPS` 后执行 `grant-nodes-from-vars.sh` 或 `deploy-master.sh`；单 IP 可用 `grant-node-mysql.sh`（仅 `EXECUTE` 存储过程）。

主控 cron（`deploy-master.sh` 写入 `/etc/cron.d/redi-renew`）：**03:10** 证书续期、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`（需 `REDI_HEALTH_WEBHOOK_URL` 方能在有 issues 时静默退出 0；勿配置 `php artisan schedule:run`）。节点 `GetSkipUrl` 与主控一致对 Host 做 **punycode** 归一。

更新 **节点二进制**（限速、Agent 等）后须在开发机 `./scripts/build-node-tar.sh` 并在各节点重跑 `deploy-node.sh`（或替换 `bin/redi-multi` 后 `systemctl restart redi-multi`）。
