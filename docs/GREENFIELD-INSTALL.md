# 绿场安装说明（无历史数据）

本仓库按 **全新部署** 设计，不依赖「从旧版升级」的一次性 SQL/迁移。

## 数据库

| 步骤 | 说明 |
|------|------|
| 建库 | `db/mysql_setup.example.sql` 或 `deploy-master.sh` |
| 导入 | `db/redi_admin.sql`：Dcat 后台菜单 + `redi_admin_*` 表结构 + 默认 `admin` |
| 迁移 | **`php artisan migrate --force`**：创建 `redi_node_bump_skip`、补全外键等（导入 SQL 后仍会跑未登记的迁移，当前为 **100009** 起） |

**不要**再维护已删除的迁移（例如原 `100010_cleanup_tenant_edge_admin_zero`，仅用于旧库 `admin_id=0` 清理）。

## 主控 / 节点

与 [DEPLOY.md](../DEPLOY.md)、[DEPLOY-centos7.md](../DEPLOY-centos7.md) 相同。CentOS 一键脚本会在 migrate 后检查存储过程是否存在。

## 纯 migrate 路径（开发）

空库 + 仅 `php artisan migrate` 时，迁移 `099998` 会创建 `redi_admin_group` / `redi_admin_domain`；仍需 Dcat 菜单数据（导入 SQL 或自行 seed）。
