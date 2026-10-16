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
| `dns_driver_invalid` | warning | DNS API 模式但驱动为 manual/未知 |
| `dns_api_credentials_missing` | warning | DNS API 模式但未保存凭证 |
| `dns_zone_id_missing` | warning | Cloudflare 驱动未配置 Zone ID |
| `node_stale` | warning | 启用节点超过 15 分钟无心跳 |
| `node_no_public_ip` | warning | 启用节点均未配置公网 IP |
| `cert_deploy_pending` | warning | 仍有 pending 证书部署 |
| `cert_deploy_failed` | critical | 节点证书安装 failed |
| `cert_expired` | critical | 已过期且域名仍启用 |
| `cert_expiring` | warning | 在 `--days` 窗口内即将到期 |
| `cert_validating_stuck` | warning | validating 超过 `REDI_HEALTH_VALIDATING_STUCK_HOURS` |
| `queue_failed_jobs` | critical | `failed_jobs` 非空 |
| `queue_backlog` | warning | `jobs` 积压超过阈值 |
| `queue_stale` | warning | `jobs` 中 `available_at` 超过 **N 小时**仍未执行（默认 **2**，`REDI_HEALTH_QUEUE_STALE_HOURS`）；仅 `QUEUE_CONNECTION=database` |

### `redi:health` 配置建议（`hints[]`，不影响退出码）

| `code` | 含义 |
|--------|------|
| `queue_cache_file` | 队列非 sync 但 `CACHE_DRIVER=file` |
| `queue_database_single_host` | 队列用 database 且与 DB 同机（多 worker 建议 Redis） |
| `agent_version_gate_off` | 生产下 `REDI_MIN_AGENT_VERSION` 为空 |

采集示例：`php artisan redi:health --json | jq '.summary.health_queue_pending_threshold,.summary.health_queue_stale_hours,.summary.health_validating_stuck_hours,.summary.health_renew_validating_stuck_hours,.issues,.hints'`

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

**2026-10-16 #88 已合并 `main`：** 见 [PR #88](https://github.com/freejbgo/301/pull/88)。

**2026-10-16 复核（#89）：** webhook 配置说明补入 `vars.example`、验收表、deploy 结束提示；`--json --notify` 单测。

**2026-10-16 #89 已合并 `main`：** 见 [PR #89](https://github.com/freejbgo/301/pull/89)。

**2026-10-16 复核（#90）：** `grant-node-mysql` / `revoke-node-mysql` 要求 root；`PRODUCTION-READINESS` / `scripts/README` 补 webhook 与 `vars.example` 索引。

**2026-10-16 #90 已合并 `main`：** 见 [PR #90](https://github.com/freejbgo/301/pull/90)。

**2026-10-16 复核（#91）：** AUDIT §7/§8 与 `DEPLOY`/`GREENFIELD` 补全 grant 脚本 sudo 用法。

**2026-10-16 #91 已合并 `main`：** 见 [PR #91](https://github.com/freejbgo/301/pull/91)。

**2026-10-16 复核（#92）：** grant/revoke 脚本头注释与 SECURITY/DEPLOY-centos7 用法统一；README 索引 firewalld 脚本。

**2026-10-16 #92 已合并 `main`：** 见 [PR #92](https://github.com/freejbgo/301/pull/92)。

**2026-10-16 #93 已合并 `main`：** 见 [PR #93](https://github.com/freejbgo/301/pull/93)（grant-nodes、firewalld、queue `--max-time`、prune sync 计数、运维脚本提示等）。

**2026-10-16 #94 已合并 `main`：** 见 [PR #94](https://github.com/freejbgo/301/pull/94)（prune 单测、#93 合并记录、Cron 日志验收提示）。

**2026-10-16 #95 已合并 `main`：** 见 [PR #95](https://github.com/freejbgo/301/pull/95)（验收 `redi-queue --max-time`、patch-cron 路径、prune 空跑单测）。

**2026-10-16 #96 已合并 `main`：** 见 [PR #96](https://github.com/freejbgo/301/pull/96)（renew 日志 summary、批量 grant firewalld 提示降噪）。

**2026-10-16 #97 已合并 `main`：** 见 [PR #97](https://github.com/freejbgo/301/pull/97)（批量 revoke 提示降噪、renew summary 单测、子 shell 错误传播）。

**2026-10-16 #98 已合并 `main`：** 见 [PR #98](https://github.com/freejbgo/301/pull/98)（`REDI_HEALTH_QUEUE_STALE_HOURS`、queue_stale 单测、PHPUnit 90）。

**2026-10-16 #99 已合并 `main`：** 见 [PR #99](https://github.com/freejbgo/301/pull/99)（health JSON summary 队列阈值、文档同步）。

**2026-10-16 #100 已合并 `main`：** 见 [PR #100](https://github.com/freejbgo/301/pull/100)（health JSON summary 单测、jq 采集示例）。

**2026-10-16 复核（#114）：** 节点 `publicIps` 过滤非法 IPv4；HTTP-01 token 校验；`NODE_IPS` 去重；PHPUnit **126**。

**2026-10-16 复核（#113）：** `node_no_public_ip` health；证书 API 跳过空域名；requeue 命令校验 ID；masteragent redirect 规则归一；PHPUnit **123**。

**2026-10-16 复核（#112）：** 证书 Job 与节点 API 统一 punycode `domain_name`；`dns_driver_invalid` health 码；masteragent 跳过空挑战域名；PHPUnit **121**。

**2026-10-16 复核（#111）：** `redi:health` 生产检查 DNS API 凭证与 Cloudflare Zone ID；ACME 挑战/HTTP-01 全链 punycode；节点路径拒绝反斜杠；PHPUnit **120**。

**2026-10-16 复核（#110）：** `dns_mode` API 大小写归一（`isDnsApiMode`）；CNAME 预检与节点 `redirect-rules` 主机名 punycode 规范化；签发前统一 `AcmeChallengeCleanup`；节点 `sanitizeHost` 在 trim 前拒绝 CRLF；PHPUnit **117**。

**2026-10-16 复核（#109）：** `REDI_ACME_HTTP01_POLL_SECONDS` 可配置轮询；签发失败 `CertificateIssuer` 内清理挑战；DNS 驱动大小写/空白归一；firewalld 脚本校验 daemon 运行；PHPUnit **113**。

**2026-10-16 复核（#108）：** `Http01PropagationWaiter` 单测；`DnsProviderFactory::API_DRIVERS` 与后台保存校验；PHPUnit **111**。

**2026-10-16 复核（#107）：** ACME 签发失败清理挑战、`CertificateIssuer` 无授权报错；`DnsProviderFactory` 未知驱动抛错；lagran `Enabled` 单测；AUDIT 全模块表；PHPUnit **109**。

**2026-10-16 复核（#106）：** `redi:normalize-domain-hosts` 修正无效 `skip_url` 检测并清空（`withoutEvents`）；prune 输出说明；`SafeRelativeRedirect` 拒绝反斜杠路径；PHPUnit **104**。

**2026-10-16 复核（#105）：** 后台运维 `_redirect` 拒绝 `//` 协议相对路径（`SafeRelativeRedirect`）；OPS/PRODUCTION 文档补 summary 字段；PHPUnit **102**。

**2026-10-16 复核（#104）：** health JSON `summary` 含 `health_renew_validating_stuck_hours`；`redi:ops-webhook` 命令单测；`CertificateIssuer` 去掉重复 `assertDomainIssuable`；`masteragent` gofmt；PHPUnit **98**。

**2026-10-16 复核（#103）：** `redi:health --notify` 固定 POST `REDI_HEALTH_WEBHOOK_URL`（与 ops URL 分离）；OPS-MULTI-WORKER jq 示例；`.env.example` 说明；PHPUnit **96**。

**2026-10-16 复核（#102）：** `challenges-synced` 的 `ignored_count` 仅计未就绪域名（空串不计）；health JSON `summary` 含 `health_validating_stuck_hours`；`requeue-failed-deployments` 命令单测；deploy 脚本统一 `redi_is_ipv4`；`scripts/README` 索引 `lagran_ports.py`；PHPUnit **95**。

**2026-10-16 复核（#101）：** §6 复核范围说明（#88–#100）；`redi_sync_node_ips_access` 批量 grant/firewalld 失败时退出并避免误写 `authorized_node_ips`；`deploy-master` 空 `NODE_IPS` 同步不再 `|| true` 掩盖撤销失败；CONSOLE 补 `requeue` 可选参数。

**2026-10-16 复核（#100）：** `redi:health --json` 单测断言 summary 阈值；AUDIT/DEPLOY-centos7 jq 采集示例；PHPUnit **92**。

**2026-10-16 复核（#99）：** `redi:health --json` summary 暴露队列 health 阈值；UPGRADE/OPS/SECURITY/CONSOLE 同步；revoke-node `REDI_GRANT_BATCH` 注释；PHPUnit **91**。

**2026-10-16 复核（#98）：** `REDI_HEALTH_QUEUE_STALE_HOURS` 可配置 `queue_stale`；renew 无待续期证书单测；grant-node 头注释 `REDI_GRANT_BATCH`；PHPUnit **90**。

**2026-10-16 复核（#97）：** 批量 `revoke-node-mysql` 同步抑制 firewalld 提示；`redi_sync_node_ips_access` 用子 shell 承载 `REDI_GRANT_BATCH`；renew 入队 summary 单测；CONSOLE 续期日志说明。

**2026-10-16 复核（#96）：** `redi:renew-certificates` 入队后打印 summary（`renew.log` 可读）；批量 `grant-node-mysql` 不再重复 firewalld 提示（`REDI_GRANT_BATCH`）；验收清单对齐 `max-time` grep。

**2026-10-16 复核（#95）：** 真机验收补 `redi-queue` `--max-time=3600` grep；`patch-master-cron` 可选 APP 路径与 README；PRODUCTION/DEPLOY-centos7 verify 脚本从仓库根执行；CONSOLE 补 Cron 日志路径；prune 空库单测；PHPUnit **88**。

**2026-10-16 复核（#94）：** `redi:prune-acme-challenges` 单测（过期/无效挑战 + sync 行）；AUDIT §6/#93 合并记录；PHPUnit **87**。

**2026-10-16 复核（#93）：** firewalld/grant-nodes 脚本用法注释；DEPLOY-centos7 手工撤销补 firewalld-revoke；UPGRADE/验收/CONSOLE 补 `grant-nodes-from-vars` 不重装主控路径；firewalld 脚本用法错误提示与仓库路径一致；`grant-nodes-from-vars` 允许空 `NODE_IPS` 撤销已记录 IP（对齐 deploy-master）；grant/revoke/deploy-node/patch-cron 运维提示；续期 `REDI_RENEW_VALIDATING_STUCK_HOURS` 写入 CONSOLE；deploy-master/deploy-node/apply-mysql-skip/firewalld/lagran-cleanup/patch-cron 的 root 提示与 `scripts/centos7/...` 路径统一；验收 FAQ 补下线节点撤销 3306；DEPLOY/SECURITY/DEPLOY-centos7 与 `deploy-master` 对齐 `queue:work --max-time=3600`；单 IP grant/revoke 脚本结束提示配套 firewalld 命令；`redi:prune-acme-challenges` 清理无效挑战时计入 sync 删除数；PRODUCTION-READINESS/UPGRADE/CONSOLE 补 `redi-queue` 验收与 unit 核对。

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
| 自动化测试 | 本地：Go 全包测试通过；PHPUnit **126**；`bash -n` + `lagran_ports.py` + procedure 校验 |
| lagran 运维 | `lagran_ports.py`、diagnose、cleanup 脚本 |
| GitHub Actions | `ci.yml` 的 `centos7-scripts` 已跑 `verify-redi-db-deploy-path.sh`（需 Docker）；PR/推送若因 Runner/账单整 job 失败，请按 §9 本地复现 |

**未发现阻塞商用的 P0 代码缺陷。**

### 全模块复核说明（每轮门禁 + #107 代码向补扫）

| 模块 | 每轮门禁 | #107 代码向处理 / 单测 |
|------|----------|-------------------------|
| **CentOS 脚本 / Cron** | `bash -n`、文档 `grep` | #101–#106 已合入栈（NODE_IPS、webhook、normalize 等） |
| **运营命令** | PHPUnit + CONSOLE | health/renew/prune/ops-webhook/requeue |
| **节点 API + Agent** | Feature `NodeAgentApiTest` + Go `masteragent` | challenges-synced、revision、证书 ack |
| **ACME 签发链** | 编译 + Job 队列单测 | `CertificateIssuer` 无授权域名时明确失败；签发失败 Job 与 Issuer 内 `AcmeChallengeCleanup`；`REDI_ACME_HTTP01_POLL_SECONDS`；`parseCertExpiry` / **`Http01PropagationWaiter`** 单测 |
| **DNS 厂商同步** | `EdgeDnsSyncServiceTest`、厂商 upsert 单测 | `isDnsApiMode()` 大小写归一；`DnsProviderFactory` 未知 `dns_driver` **抛错**；`API_DRIVERS` + 后台校验 |
| **Go lagran** | `go test ./...` | 新增 `lagran/enabled_test.go`（`REDI_LAGRAN_DISABLED`）；`lagran_ports.py` + 运维脚本 |
| **后台 Dcat** | 手工扫路由/middleware | #105 `_redirect` 防 `//`；未全量 UI 审计 |
| **文档** | 与脚本/命令对齐 | AUDIT / DEPLOY / SECURITY / OPS 随 PR 更新 |
| **刻意不做** | — | Laravel/Dcat 大版本、composer CVE 批量删 vendor、`lagran.go` NFQUEUE 内核逻辑重写 |

每轮「全库」= 上述模块 **全部跑门禁**；代码行级深读按模块逐步加深（#107 起 ACME/DNS/lagran 已纳入表内项，而非仅 Cron）。

### 已知非阻塞项（P2 排期）

- `composer audit` 对 `symfony/yaml` 等传递依赖有 CVE 公告 → 随 Laravel/Composer 升级处理。
- 真机验收清单仍以人工打勾为主（无全自动真机 CI）。
- CentOS 7 EOL：继续用 vault 源；长期换 OS 属架构决策。

---

## 7. 仓库合并基线

跟踪 **`main`**，合并基线含 [#75](https://github.com/freejbgo/301/pull/75)–[#101](https://github.com/freejbgo/301/pull/101)（#101：AUDIT 复核范围 + NODE_IPS grant 错误传播；#100：health JSON 单测与 jq 示例）。升级见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)。

---

## 8. VPS 操作顺序（若尚未对齐 main）

1. 将主控/节点升到当前 **main**。
2. 主控：`docs/UPGRADE-IN-PLACE.md` → 备份 `.env`、新 tar、`migrate`、`config:cache`；`sudo bash scripts/patch-master-cron-ops-webhook.sh`（纠正 Cron 中错误的 `master/master` 路径），并执行：
   ```bash
   grep -E 'renew|prune|health' /etc/cron.d/redi-renew
   ! grep -q 'schedule:run' /etc/cron.d/redi-renew
   ! grep -q 'master/master' /etc/cron.d/redi-renew
   grep -q '\-\-max-time=3600' /etc/systemd/system/redi-queue.service
   ```
   并在 `/opt/301/master/.env` 配置 `REDI_HEALTH_WEBHOOK_URL`（可选 `REDI_OPS_WEBHOOK_URL`），`php artisan config:cache` 后验证 `sudo -u nginx php artisan redi:health --notify`（见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) §4）。
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

PHPUnit 当前 **126** 条 Feature/Unit；以 `phpunit` 退出码为准。若 Actions 未跑完即失败，以上命令在开发机通过即可视为脚本/单测门禁满足。
