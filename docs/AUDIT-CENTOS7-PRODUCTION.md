# CentOS 7.9 生产复核清单

本文档是对 **全仓库生产就绪** 的一次性复核摘要：部署面仅 **CentOS 7.9（7.x）**；Ubuntu 部署脚本与文档已从仓库移除。

关联文档：

| 文档 | 用途 |
|------|------|
| [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) | 阻塞项与验收命令 |
| [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md) | P0/P1/P2 路线图 |
| [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) | 真机逐步打勾 |
| [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) | 已上线环境增量升级 |
| [../scripts/dev-preflight.sh](../scripts/dev-preflight.sh) | 开发机发版前自检（§9） |

---

## 1. 部署与运维面（CentOS only）

| 项 | 结论 |
|----|------|
| 主控/节点一键脚本 | `scripts/centos7/deploy-master.sh`、`deploy-node.sh` |
| 主控程序目录 | 发布包解压后为 **`/opt/301/master`**（`artisan` 在此）；非 `/opt/301/master/master` |
| 变量模板 | `scripts/centos7/vars.example.env` → 本地 `vars.env`（gitignore） |
| MySQL 节点授权 / 防火墙 | `grant-nodes-from-vars.sh`、`firewalld` 按 `NODE_IPS` |
| 存量 Cron 补 Webhook | `scripts/patch-master-cron-ops-webhook.sh`（默认 `nginx` 用户） |
| CI 校验 | `scripts/dev-preflight.sh`（或 §9 分步）、`container-integration-test.sh`（release 门禁） |
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
| 健康检查 | `php artisan redi:health` / `--json` / `--notify`（Cron：`--notify` 在 webhook **发送成功**或无 issues 时退出 **0**） |
| 续期 | Cron `redi:renew-certificates`；`--dry-run --days=` |
| 失败告警 | 续期/清理/health Cron 行尾 `|| redi:ops-webhook`（health 在 webhook 未发出且有 issues 时退出非 0 触发）；`.env` URL |
| 队列健康 | health 内 `failed_jobs`、积压阈值 |
| 后台 | 节点证书列、重试失败、域名筛选/导出 CSV、批量重下发 |
| 全局重试 | `redi:requeue-failed-deployments` |

### `redi:health` 问题码（`issues[]`，影响退出码）

| `code` | 级别 | 含义 |
|--------|------|------|
| `app_debug_enabled` | critical | 生产环境 `APP_DEBUG=true` |
| `acme_email_missing` | warning | 未配置 ACME 联系邮箱 |
| `acme_staging_enabled` | warning | 生产仍开启 ACME Staging |
| `edge_zone_missing` | warning | 未配置平台 `edge_zone` |
| `node_stale` | warning | 启用节点超过 15 分钟无心跳 |
| `cert_deploy_pending` | warning | 仍有 pending 证书部署 |
| `cert_deploy_failed` | critical | 节点证书安装 failed |
| `cert_expired` | critical | 已过期且域名仍启用 |
| `cert_expiring` | warning | 在 `--days` 窗口内即将到期 |
| `cert_validating_stuck` | warning | validating 超过 `REDI_HEALTH_VALIDATING_STUCK_HOURS` |
| `queue_failed_jobs` | critical | `failed_jobs` 非空 |
| `queue_backlog` | warning | `jobs` 积压超过阈值 |
| `queue_stale` | warning | `jobs` 中 `available_at` 超过 **2 小时**仍未执行（worker 可能未跑）；仅 `QUEUE_CONNECTION=database` |

### `redi:health` 配置建议（`hints[]`，不影响退出码）

| `code` | 含义 |
|--------|------|
| `queue_cache_file` | 队列非 sync 但 `CACHE_DRIVER=file` |
| `queue_database_single_host` | 队列用 database 且与 DB 同机（多 worker 建议 Redis） |
| `agent_version_gate_off` | 生产下 `REDI_MIN_AGENT_VERSION` 为空 |

采集示例：`php artisan redi:health --json | jq '.issues,.hints'`

**上线后建议：** 配置 `REDI_HEALTH_WEBHOOK_URL`（或 ops Webhook），监控采集 `redi:health --json`；真机按 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 打勾。

---

## 4. P2 — 未阻塞首单（排期）

| 项 | 说明 |
|----|------|
| Laravel / PHP 大版本 | 当前锁 PHP 8.0 兼容；长期需升级计划 |
| MySQL 客户端 TLS | 节点连主控库可考虑加密通道 |
| lagran NFQUEUE | 诊断/清理与 `lagran_ports.py` 已脚本化；更深自动化仍排期 |
| 租户隔离审计 | API/后台更强审计与单测 |

---

## 5. 产品行为（复核确认非缺陷）

- 正常跳转：**HTTP 200 + JS**，不是业务层 301。
- **强制 HTTPS**：TLS 层 301。
- 「抢注」：平台内 `original_url` 唯一；缓解手段为 **默认停用 + 启用前 DNS**，非注册商抢注。

---

## 6. 全面复核记录（2026-10）

**2026-10-16 全库复核（#83 后）：** `go test ./...` + PHPUnit **80** + `dev-preflight` 通过；`scripts/` 与 `centos7/` 脚本均有文档或 CI 引用；`public/vendor/dcat-admin` 为 Dcat 静态资源，**不删**；移除 `inspire` 示例；根 `.gitignore` 补充本地缓存。

**2026-10-16 深度审计：** 删除未使用的 `UserFactory`、`node/redi-multi/Makefile`；清空 `Kernel::schedule`（生产仅用 `/etc/cron.d`，避免误跑 `schedule:run` 双份续期）；`channels.php` 与未启用的 Broadcast 对齐；CI 下 `verify-redi-db-deploy-path` 无 docker 时失败。

**2026-10-16 复核（#87）：** Cron 文档统一「勿 `schedule:run`」；`UPGRADE` / 验收清单补强 `grep renew|prune|health` 与 `schedule:run` 负向检查。

**2026-10-16 深度复核（#88）：** `deploy-master` / `patch-master-cron` 安装后打印 Cron 验收；`DEPLOY-centos7` §11、`CONSOLE` 运营命令与 `queue_stale` 说明补齐；`scripts/README` 索引 CentOS 辅助脚本；`queue_stale` 单测；`SECURITY` Cron 时段说明；`redi:health --notify` 在 webhook 成功时退出 0、失败时由 `|| ops-webhook` 兜底；`grant-nodes-from-vars` 要求 root。

| 维度 | 结论 |
|------|------|
| 部署脚本 | `deploy-master` / `deploy-node` 仅接受 CentOS 7；Cron 用户 `nginx`；`NODE_IPS` 同步授权与 firewalld 逻辑完整 |
| 节点 Agent | 代码版本 `0.1.3` 与 `REDI_MIN_AGENT_VERSION` 一致；心跳先于拉取；ack 403/409 不触发重注册 |
| 证书链路 | `syncDeploymentsForNode` / `markNodeAck` 幂等与状态机经单测覆盖；空证书列表清空节点内存 |
| 后台安全 | `RequireAdminSessionForDcatApi` + 默认密码拦截；节点 API 认证后 `throttle:redi-node` |
| DNS 启用 | `DomainDnsPointingGuard` + 批量创建默认停用 |
| 运营命令 | `redi:health`、`renew`、`ops-webhook`、`requeue-failed-deployments` 与文档一致 |
| 文档链接 | 已无指向已删 Ubuntu 路径的引用 |
| 自动化测试 | 本地：Go 全包测试通过；PHPUnit **84**；`bash -n` + `lagran_ports.py` + procedure 校验 |
| lagran 运维 | `lagran_ports.py`、diagnose、cleanup 脚本 |
| GitHub Actions | `ci.yml` 的 `centos7-scripts` 已跑 `verify-redi-db-deploy-path.sh`（需 Docker）；PR/推送若因 Runner/账单整 job 失败，请按 §9 本地复现 |

**未发现阻塞商用的 P0 代码缺陷。**

### 已知非阻塞项（P2 排期）

- `composer audit` 对 `symfony/yaml` 等传递依赖有 CVE 公告 → 随 Laravel/Composer 升级处理。
- 真机验收清单仍以人工打勾为主（无全自动真机 CI）。
- CentOS 7 EOL：继续用 vault 源；长期换 OS 属架构决策。

---

## 7. 仓库合并基线

跟踪 **`main`**，合并基线含 [#75](https://github.com/freejbgo/301/pull/75)–[#88](https://github.com/freejbgo/301/pull/88)（深度审计、`Kernel` schedule 留空、Cron 文档/脚本/验收 grep 统一）。升级见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)。

---

## 8. VPS 操作顺序（若尚未对齐 main）

1. 将主控/节点升到当前 **main**。
2. 主控：`docs/UPGRADE-IN-PLACE.md` → 备份 `.env`、新 tar、`migrate`、`config:cache`；`sudo bash scripts/patch-master-cron-ops-webhook.sh`（纠正 Cron 中错误的 `master/master` 路径），并执行：
   ```bash
   grep -E 'renew|prune|health' /etc/cron.d/redi-renew
   ! grep -q 'schedule:run' /etc/cron.d/redi-renew
   ! grep -q 'master/master' /etc/cron.d/redi-renew
   ```
3. 各节点：升级 **Agent 0.1.3** 的 `redi-multi` 并重启服务。
4. 跑 `php artisan redi:health`（会附带打印配置 **hints**）与 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 中的 curl/DB 校验。
5. 按 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 完成真机验收（含第二节点、证书、CNAME 场景见 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)）。

---

## 9. 本地/CI 快速自检（开发机）

与 GitHub **CI** 对齐：`node` + `master-phpunit`（8.4/8.0）+ `centos7-scripts`（含下方脚本校验）+ 可选 `centos7-integration` 容器冒烟。打 `v*` 标签时 **release.yml** 在上传 Release 前跑同一套检查。

一键（推荐）：

```bash
bash scripts/dev-preflight.sh
```

分步：

```bash
# Go
cd node/redi-multi && go test ./...

# PHP（需 8.0+ 与 composer）
cd master/master && composer install && ./vendor/bin/phpunit --testsuite Unit,Feature

# 部署脚本语法（= CI job centos7-scripts 核心步骤）
for f in scripts/centos7/*.sh scripts/patch-master-cron-ops-webhook.sh; do bash -n "$f"; done
python3 scripts/centos7/lagran_ports.py node/redi-multi/config.json.example | grep -q '80'
php scripts/verify-redi-node-procedure-sync.php
bash scripts/verify-redi-db-deploy-path.sh   # 需 Docker；本地无 docker 跳过，CI 无 docker 则失败
```

`verify-redi-db-deploy-path.sh` 用临时 MariaDB 10.6 验证 `utf8mb4_unicode_ci` 建库 + `redi_admin.sql` 导入后 `CALL redi_node_bump_skip` 可用（与 `deploy-master` 路径一致）。

PHPUnit 当前 **84** 条 Feature/Unit；以 `phpunit` 退出码为准。若 Actions 未跑完即失败，以上命令在开发机通过即可视为脚本/单测门禁满足。
