# 产品阅读说明（绿场）

把本仓库当作 **从未对外发布过的新产品** 阅读与安装，不要按「从初代程序升级 / 数据迁移 / 二次开发台账」去理解文档与脚本。

## 文档里有什么

| 类型 | 说明 |
|------|------|
| **安装与发布** | 分步教程、CentOS 脚本、`vars` 模板、tar 打包 |
| **运营** | 控制台、健康检查、客户 CNAME 说明、上线检查单 |
| **换包** | 仅当机房**已在跑**时见 [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md) |

**不包含**：审计周报、需求开发进度、IPv6 实施台账、AUDIT 专章等历史文档（仓库内亦无对应文件）。

## 唯一推荐的生产路径

1. 发版机：`bash scripts/dev-preflight.sh` → `bash scripts/build-release-tarballs.sh`
2. 机房：CentOS 7.x + `vars.env` + `deploy-master.sh` / `deploy-node.sh`
3. 数据库：**一次导入** [db/redi_admin.sql](../db/redi_admin.sql)（见 [GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md)）
4. 验收：[LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)

索引：[docs/README.md](README.md) · 部署摘要：[DEPLOY.md](../DEPLOY.md)

## 与「旧环境」无关的说明

- `migrate --force` 是 Laravel 常规步骤；绿场导入当前 SQL 后通常 **无待执行迁移**。
- `redi:normalize-platform-branding`、换包文档中的修补项，面向 **极少数** 从外部拷贝来的配置；**全新导入 `redi_admin.sql` 可不做**。
- `scripts/patch-master-cron-ops-webhook.sh` 仅用于 **Cron 已落后于当前模板** 的在跑主控；**绿场 `deploy-master.sh` 勿用**。
