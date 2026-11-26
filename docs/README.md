# 文档索引

请先读 **[GREENFIELD-PRODUCT.md](GREENFIELD-PRODUCT.md)**（全新产品边界 + **按角色阅读顺序**）。下文按 **首装 → 签收 → 运营 → 参考** 排列；无二次开发台账、问题修复周报或「从初代程序迁移」专章。

## 首装（绿场）

| 顺序 | 文档 |
|------|------|
| 0 | [GREENFIELD-PRODUCT.md](GREENFIELD-PRODUCT.md) — 怎么读文档 |
| 1 | [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md) — **主控和节点各一条命令**（`master.sh` / `node.sh`） |
| 1b | [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) — **分步教程**（同一套脚本，逐步说明） |
| 2 | [GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md) — 一次导入 `redi_admin.sql`、首登 |
| 3 | [DEPLOY-centos7.md](../DEPLOY-centos7.md) — 脚本细节与可选章节 |
| 4 | [vars.example.env](../scripts/centos7/vars.example.env)、[vars.production.example.env](../scripts/centos7/vars.production.example.env) — `vars.env` 模板 |

部署路径摘要：[DEPLOY.md](../DEPLOY.md)。

## 签收与验收

| 文档 | 何时用 |
|------|--------|
| [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) | **上线前**一页打勾（多数团队的主签收单） |
| [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) | 检查单里某项需要命令与背景说明时 |
| [ACCEPTANCE.md](ACCEPTANCE.md) | **§A** 安装逐项核对 + **§B** 业务 F1–F8 |

## 首装后换发布包

仅当环境**已在跑**：[OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)。

## 日常运营

| 文档 | 用途 |
|------|------|
| [CONSOLE.md](CONSOLE.md) | 后台操作、DNS 同步排障、平台边界 §8 |
| [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md) | 发给客户的 CNAME 说明 |
| [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md) | `redi:health` 各 code |
| [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md) | 多 worker / Redis |
| [NETWORKING-IPv6.md](NETWORKING-IPv6.md) | Pool AAAA 与双栈（可选能力） |

## 开发与参考

| 文档 | 用途 |
|------|------|
| [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md) | 本地双 tar + scp；可选 GitHub Release |
| [DEVELOPMENT.md](DEVELOPMENT.md) | `dev-preflight` 与发版 |
| [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md) | DNS 模型、节点 API、数据表（**首装不必先读**） |
| [I18N.md](I18N.md) | 后台三语与运维英文约定 |
| [SECURITY.md](../SECURITY.md) | 密码、端口、Agent |
| [scripts/README.md](../scripts/README.md) | verify 脚本 |
