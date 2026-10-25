# 文档索引

按场景选路径即可；无需对照历史 PR 或开发进度表。

## 新环境上线

1. [GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md) — 导入 `redi_admin.sql`  
2. [DEPLOY-centos7.md](../DEPLOY-centos7.md) — 主控/节点脚本  
3. [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) — 真机打勾  
4. [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) — 业务 F1–F8  
5. [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) — **一页上线打勾**（详细说明见 PRODUCTION-READINESS）  
6. [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) — 阻塞项与验收命令  

## 已上线升级

[UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)

## 日常运营

| 文档 | 用途 |
|------|------|
| [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) | 上线打勾（可配合 Canvas 检查单） |
| [CONSOLE.md](CONSOLE.md) | 后台操作、DNS 同步排障、平台边界 §8 |
| [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md) | 发给客户的 CNAME 说明 |
| [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md) | `redi:health` 各 code |
| [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md) | 多 worker / Redis |
| [NETWORKING-IPv6.md](NETWORKING-IPv6.md) | Pool AAAA 与双栈 |

## 理解与开发

| 文档 | 用途 |
|------|------|
| [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md) | DNS 模型、节点 API、数据表 |
| [DEVELOPMENT.md](DEVELOPMENT.md) | `dev-preflight` 与发版 |
| [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md) | 长期限制与计划中的增强 |
| [SECURITY.md](../SECURITY.md) | 密码、端口、Agent |
| [scripts/README.md](../scripts/README.md) | verify 脚本 |
