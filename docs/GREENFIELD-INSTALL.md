# 绿场安装说明（无历史数据）

本仓库按 **全新部署** 设计：没有「从旧版 301 升级」的路径，也 **不要** 再执行已删除的一次性清理迁移（原 `100010_cleanup_tenant_edge_admin_zero`）。

## 文档索引

| 文档 | 用途 |
|------|------|
| [DEPLOY.md](../DEPLOY.md) | 通用部署（手工 / 非 CentOS 脚本） |
| [DEPLOY-centos7.md](../DEPLOY-centos7.md) | CentOS 7 一键 `deploy-master.sh` / `deploy-node.sh` |
| [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md) | `vars.env` 填 IP 示例 |
| [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) | 真机逐步验收 |
| [docs/CONSOLE.md](CONSOLE.md) | 后台操作 |
| [SECURITY.md](../SECURITY.md) | 密码、端口、节点权限 |

## 数据库：SQL 与 migrate 各做什么

| 步骤 | 命令 / 动作 | 内容 |
|------|-------------|------|
| 1 建库 | `db/mysql_setup.example.sql` 或 `deploy-master.sh` | 创建 `redi_admin` 库与 `redi_admin` 应用账号 |
| 2 导入 | `db/redi_admin.sql` | Dcat **菜单/权限**、**redi_admin_group / redi_admin_domain** 等表结构（**不含**示例分组数据）、默认超管 `admin` |
| 3 迁移 | **`php artisan migrate --force`** | 登记在 SQL 之后的迁移；**必须执行**。其中 **`100009`** 创建节点存储过程 **`redi_node_bump_skip`**（SQL 文件里 **不再** 重复写过程体，避免与 migrate 双份维护） |

导入 SQL 后若 **不** 跑 migrate，节点无法 `CALL redi_node_bump_skip`，跳转页 `/api.js` 会连不上库逻辑。

**验证（主控本机）：**

```bash
mysql -u redi_admin -p redi_admin -e \
  "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'"
```

CentOS 的 `deploy-master.sh` 在 migrate 后会自动检查该过程是否存在。

### 迁移 `099998`（仅空库 migrate 时）

若 **不** 导入 `redi_admin.sql`，只对空库执行 `php artisan migrate`，迁移 **`099998_create_redi_admin_core_tables`** 会创建 `redi_admin_group` / `redi_admin_domain`（结构与 SQL 中一致）。此时仍缺 Dcat 菜单，需另做 seed 或导入 SQL 中的 admin 部分。

## 主控首次登录

- 默认账号：`admin` / `Redi301@Install2026`（见 [DEPLOY.md](../DEPLOY.md)）。
- 仍为默认密码时，后台会 **强制跳转改密**（`auth/setting`）。内网演示可在 `.env` 设 `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false`。

## 节点（被干扰域名场景）

- 发布包 `node/redi-multi.tar.gz`：**不含**本机 `config.json`，首次从 `config.json.example` 复制；**lagran 默认开启**。
- `deploy-node.sh` 会 `setcap cap_net_admin`，并在应开启 lagran 时检查 capability 与日志。
- 跳转配置通过访客域名上的 **`/api.js`** 从 MySQL 拉取（见 [node/README.md](../node/README.md)）。

## 更新代码后（仍非「旧版升级」）

拉新主控代码后在本机执行：

```bash
cd /opt/301/master   # 或你的程序目录
php artisan migrate --force
```

若节点 MySQL 账号为 **`redi_node`**，对新增节点 IP 重跑 `grant-node-mysql.sh`（仅 `EXECUTE` 存储过程）。
