# CentOS 7.9 生产复核清单

本文档是对 **全仓库生产就绪** 的一次性复核摘要：部署面仅 **CentOS 7.9（7.x）**；Ubuntu 部署脚本与文档已从仓库移除。

关联文档：

| 文档 | 用途 |
|------|------|
| [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) | 阻塞项与验收命令 |
| [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md) | P0/P1/P2 路线图 |
| [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) | 真机逐步打勾 |
| [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) | 已上线环境增量升级 |

---

## 1. 部署与运维面（CentOS only）

| 项 | 结论 |
|----|------|
| 主控/节点一键脚本 | `scripts/centos7/deploy-master.sh`、`deploy-node.sh` |
| 变量模板 | `scripts/centos7/vars.example.env` → 本地 `vars.env`（gitignore） |
| MySQL 节点授权 / 防火墙 | `grant-nodes-from-vars.sh`、`firewalld` 按 `NODE_IPS` |
| 存量 Cron 补 Webhook | `scripts/patch-master-cron-ops-webhook.sh`（默认 `nginx` 用户） |
| CI 校验 | `bash -n scripts/centos7/*.sh`、`verify-redi-*`、`container-integration-test.sh` |
| Ubuntu 路径 | **已删除**（`scripts/ubuntu/`、`DEPLOY-ubuntu*.md`、`ACCEPTANCE-UBUNTU-DEPLOY.md`） |

---

## 2. P0 — 阻塞商用（代码侧已落地）

| 领域 | 要点 | 验收提示 |
|------|------|----------|
| 域名占坑 | 新建默认停用；启用前 DNS（CNAME/A 池），超管可跳过 | 后台启用域名，未解析应被拒绝 |
| 后台安全 | 默认密码拦截含 dcat-api；API 须登录；登录限速；分组名转义 | 未改密码无法进后台；未登录调 dcat-api 401 |
| 证书下发 | register / 节点重新启用时补齐部署行；主控空列表节点清空内存证 | 新启用节点应出现 pending 部署并 ack |
| 节点 Agent | ≥ `0.1.3`；心跳优先；ack 403/409 不重注册；认证后限流 | `.env` `REDI_MIN_AGENT_VERSION=0.1.3` |
| 数据与部署 | `redi_admin.sql` 绿场一次导入；`verify-redi-db-deploy-path.sh` | CI 与主控本机可跑 |

---

## 3. P1 — 日常运营（已合入，需配置）

| 能力 | 命令 / 入口 |
|------|-------------|
| 健康检查 | `php artisan redi:health` / `--json` / `--notify` |
| 续期 | Cron `redi:renew-certificates`；`--dry-run --days=` |
| 失败告警 | Cron 行尾 `|| redi:ops-webhook`；`.env` Webhook URL |
| 队列健康 | health 内 `failed_jobs`、积压阈值 |
| 后台 | 节点证书列、重试失败、域名筛选/导出 CSV、批量重下发 |
| 全局重试 | `redi:requeue-failed-deployments` |

**上线后建议：** 配置 `REDI_HEALTH_WEBHOOK_URL`（或 ops Webhook），监控采集 `redi:health --json`；真机按 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 打勾。

---

## 4. P2 — 未阻塞首单（排期）

| 项 | 说明 |
|----|------|
| Laravel / PHP 大版本 | 当前锁 PHP 8.0 兼容；长期需升级计划 |
| MySQL 客户端 TLS | 节点连主控库可考虑加密通道 |
| lagran NFQUEUE | 升级/卸载脚本化、文档化 |
| 租户隔离审计 | API/后台更强审计与单测 |

---

## 5. 产品行为（复核确认非缺陷）

- 正常跳转：**HTTP 200 + JS**，不是业务层 301。
- **强制 HTTPS**：TLS 层 301。
- 「抢注」：平台内 `original_url` 唯一；缓解手段为 **默认停用 + 启用前 DNS**，非注册商抢注。

---

## 6. 全面复核记录（2026-10）

| 维度 | 结论 |
|------|------|
| 部署脚本 | `deploy-master` / `deploy-node` 仅接受 CentOS 7；Cron 用户 `nginx`；`NODE_IPS` 同步授权与 firewalld 逻辑完整 |
| 节点 Agent | 代码版本 `0.1.3` 与 `REDI_MIN_AGENT_VERSION` 一致；心跳先于拉取；ack 403/409 不触发重注册 |
| 证书链路 | `syncDeploymentsForNode` / `markNodeAck` 幂等与状态机经单测覆盖；空证书列表清空节点内存 |
| 后台安全 | `RequireAdminSessionForDcatApi` + 默认密码拦截；节点 API 认证后 `throttle:redi-node` |
| DNS 启用 | `DomainDnsPointingGuard` + 批量创建默认停用 |
| 运营命令 | `redi:health`、`renew`、`ops-webhook`、`requeue-failed-deployments` 与文档一致 |
| 文档链接 | 已无指向已删 Ubuntu 路径的引用 |
| 自动化测试 | 本地：Go 全包测试通过；PHPUnit **78**；`bash -n` + `lagran_ports.py` + procedure 校验 |
| lagran 运维 | `lagran_ports.py`、diagnose、cleanup 脚本 |
| GitHub Actions | 部分环境 CI 可能因 Runner/账单秒失败；以本地上述自检为准 |

**未发现阻塞商用的 P0 代码缺陷。**

### 已知非阻塞项（P2 排期）

- `composer audit` 对 `symfony/yaml` 等传递依赖有 CVE 公告 → 随 Laravel/Composer 升级处理。
- 真机验收清单仍以人工打勾为主（无全自动真机 CI）。
- CentOS 7 EOL：继续用 vault 源；长期换 OS 属架构决策。

---

## 7. 仓库合并基线

跟踪 **`main`**（已含 #75–#79：P0/P1、health、lagran、复核修复与小优化）。升级见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)。

---

## 8. VPS 操作顺序（若尚未对齐 main）

1. 将主控/节点升到当前 **main**。
2. 主控：`docs/UPGRADE-IN-PLACE.md` → 新 tar、`migrate`、`config:cache`、`patch-master-cron-ops-webhook.sh`。
3. 各节点：升级 **Agent 0.1.3** 的 `redi-multi` 并重启服务。
4. 跑 `php artisan redi:health`（会附带打印配置 **hints**）与 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 中的 curl/DB 校验。
5. 按 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 完成真机验收（含第二节点、证书、CNAME 场景见 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)）。

---

## 9. 本地/CI 快速自检（开发机）

```bash
# Go
cd node/redi-multi && go test ./...

# PHP（需 8.0+ 与 composer）
cd master/master && composer install && ./vendor/bin/phpunit --testsuite Unit,Feature

# 部署脚本语法
for f in scripts/centos7/*.sh scripts/patch-master-cron-ops-webhook.sh; do bash -n "$f"; done
php scripts/verify-redi-node-procedure-sync.php
bash scripts/verify-redi-db-deploy-path.sh
```

PHPUnit 用例数随分支演进（当前 **76+** 条 Feature/Unit）；以 `phpunit` 退出码为准。
