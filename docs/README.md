# 文档索引

文档描述 **当前产品** 在 **绿场首装** 下的行为与运营方式，不含历史迁移台账或二次开发进度。

## 新环境上线（推荐路径）

1. **[DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md)** — 从零到后台可用（打 tar → 主控 → 节点 → 控制台）
2. [GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md) — `redi_admin.sql` 与存储过程
3. [DEPLOY-centos7.md](../DEPLOY-centos7.md) — `deploy-*.sh` 行为与可选项
4. [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md) — 实验室 `vars.env` 填空
5. [../scripts/centos7/vars.production.example.env](../scripts/centos7/vars.production.example.env) — **生产** `vars.env` 模板（HTTPS、须上传 tar）
6. [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) / [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) — 真机与业务验收
7. [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) / [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) — 上线打勾与阻塞项

根目录 [DEPLOY.md](../DEPLOY.md) 为架构与手工参考；**生产请以上述 CentOS 教程为准**，勿在机房 `git clone` 后手工 `composer install`。

## 首装后维护

| 文档 | 用途 |
|------|------|
| [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md) | 更换主控/节点 tar、cron/webhook 核对 |
| [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md) | 发版机打 tar、`REDI_*_TAR` |

## 日常运营

| 文档 | 用途 |
|------|------|
| [CONSOLE.md](CONSOLE.md) | 后台操作、DNS 同步排障 §8 |
| [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md) | 发给客户的 CNAME 说明 |
| [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md) | `redi:health` 各 code |
| [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md) | 多 worker / Redis |
| [NETWORKING-IPv6.md](NETWORKING-IPv6.md) | Pool AAAA 与双栈 |

## 架构与发版（维护者）

| 文档 | 用途 |
|------|------|
| [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md) | DNS 模型、节点 API、数据表 |
| [DEVELOPMENT.md](DEVELOPMENT.md) | `dev-preflight`、verify 索引 |
| [I18N.md](I18N.md) | 后台三语约定 |
| [SECURITY.md](../SECURITY.md) | 密码、端口、Agent |
| [scripts/README.md](../scripts/README.md) | `verify-*` 脚本 |
