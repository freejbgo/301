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

### PR #145–#220 复核纪要（2026-10-17，北京日）

| 项 | 结论 |
|----|------|
| 范围 | **76** 个 PR（#145–#220 已合并 `main`）；相对 #144 合并点约 **98** 文件、+4326/-425 行 |
| 门禁 | `bash scripts/dev-preflight.sh`：**417** tests / **893** assertions；Go 测试通过 |
| A. 节点 API / Agent（#145–#174, #198–#200） | ack 校验、控制字符过滤、`redirect-rules` / challenges 出站域名校验；与 Go `AcmeHostKey` 对齐 |
| B. ACME / 主机名（#175–#197） | `acmeHostKey`、`nodePublishableRow`、`originalUrlIfValid` 收敛；purge 先增后删与 legacy 键兼容 |
| C. 证书漂移与 realign（#187–#240） | 只读扫描 + `freshCertWithPersistedRealign` 落库；`saveQuietly` + 显式 `reconcile`；节点 `ack` 行锁内对齐 |
| D. 可执行部署指标（#201–#206） | `certIsNodePullable` / `countActionableDeployments` 消除僵尸 pending；指标缓存 `forgetActionableDeploymentMetrics` |
| E. 域名/节点生命周期（#205–#214） | 停用清部署行 + `DISABLE_DEPLOY_CANCEL_MSG`；2→1 自动 `queueDeploy`；节点 `enabled=false` 删部署行 |
| F. 部署行清理与状态机（#215–#219） | `pruneNonPullableDeployments`、`pruneStaleDeploymentsForNode`、`refreshCertStatusFromDeployments`、`reconcileCertDeployStatus` |
| 已修缺口（#219 合并） | PEM/主机名变更后仍显示 **active** → 模型钩子 + reconcile |
| #220 已合并 | `refuseQueueDeploy` 先 reconcile 再写跳过文案 |
| 后续观察（非阻塞） | ① 无心跳节点 pending 不阻塞 active（#218 设计如此）② 单测库常无 `redi_cert_deployments` 表，已用 `Schema::hasTable` 守卫 |

### 节点 revision / 拉取 API 深度扫 — 收口（#273，2026-10-18）

| 检查项 | 状态 |
|--------|------|
| `rules_revision` ↔ `redirect-rules`（过滤、`id` 序、scheme 规范化） | ✅ #269–#271 |
| `challenges_revision` ↔ `acme-challenges`（可发布行、`id` 序、过期/非法 token） | ✅ #270–#272 |
| `certificates_revision` ↔ `/certificates`（本节点部署行、prune、`id` 序、`force_https`） | ✅ #271–#273 |
| 部署 prune / `markNodeAck` / 他节点失败驱动分项 revision | ✅ #254–#260、#272 |
| 组合 `revision` = 三项分项 SHA1 嵌套 | ✅ #273 Feature 契约测 |
| Go `masteragent` 分项 revision 仅拉变更项 | ✅ #273 `tick_test` |

**结论（代码侧）：** 上述子域在 `main` 上视为 **已收口**；除非改节点 API 行为或真机验收发现问题，**不再**按「继续」做小步堆 PR。剩余风险见 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md)（公网 HTTP-01、双节点时序）。

### 运维 Agent「继续」约定（2026-10-17）

用户说 **「继续」** 时按序执行：

1. **复核**当前草稿 PR 与当日已合并链路（逻辑、测试、文档一致性）；有问题先在同一迭代修完。
2. **合并**草稿 PR 进 `main` 并 **删除** 远程特性分支。
3. **全库深度扫**（PHP/Go/脚本/文档），优先修容易出错的边界（证书部署、ACME、DNS、节点 Agent）。
4. 跑 `bash scripts/dev-preflight.sh`，更新本文档进度，再开下一号草稿 PR。

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
| `node_pool_empty` | warning | 没有启用的边缘节点 |
| `node_no_public_ip` | warning | 启用节点均未配置公网 IP |
| `cert_deploy_pending` | warning | 仍有节点可拉取的 pending 证书部署（与 `/api/nodes/certificates` 一致，忽略无 PEM/漂移/停用域名的僵尸 pending） |
| `cert_deploy_failed` | critical | 节点证书安装 failed（仅统计仍可下发的证书，与 pending 规则一致） |
| `cert_expired` | critical | 已过期且域名仍启用 |
| `cert_expiring` | warning | 在 `--days` 窗口内即将到期 |
| `cert_validating_stuck` | warning | validating 超过 `REDI_HEALTH_VALIDATING_STUCK_HOURS` |
| `cert_domain_name_drift` | warning | 证书 `domain_name` 与域名 `original_url` 不一致且 health 未能自动对齐 |
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

**2026-10-20 全库深度扫（#334 续）：** `SafeRelativeRedirect` 接受同域 `admin_url()` 绝对 URL 并规范为相对路径（修复运营「重试失败部署」`_redirect` 失效）；`redirect-rules` / `rules_revision` 主机名键改为 `acmeHostKey`（与 Go `AcmeHostKey` 一致）。

**2026-10-20 全库深度扫（#331 + #332 已合并）：** #331：`AcmeHostKey` 拒 IPv4、TLS 查表规范化；#332：`rules`/证书内存键统一 `AcmeHostKey`，`SafeRelativeRedirect` 拒 `\0`/`\t`；门禁 **417** / **893**。

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

**2026-10-17 #199 已合并 `main`：** 见 [PR #199](https://github.com/freejbgo/301/pull/199)。

**2026-10-17 #200 已合并 `main`：** 见 [PR #200](https://github.com/freejbgo/301/pull/200)。

**2026-10-17 #201 已合并 `main`：** 见 [PR #201](https://github.com/freejbgo/301/pull/201)。

**2026-10-17 #202 已合并 `main`：** 见 [PR #202](https://github.com/freejbgo/301/pull/202)。

**2026-10-17 #203 已合并 `main`：** 见 [PR #203](https://github.com/freejbgo/301/pull/203)。

**2026-10-17 #204 已合并 `main`：** 见 [PR #204](https://github.com/freejbgo/301/pull/204)。

**2026-10-17 #205 已合并 `main`：** 见 [PR #205](https://github.com/freejbgo/301/pull/205)。

**2026-10-17 #206 已合并 `main`：** 见 [PR #206](https://github.com/freejbgo/301/pull/206)。

**2026-10-17 #207 已合并 `main`：** 见 [PR #207](https://github.com/freejbgo/301/pull/207)。

**2026-10-17 #208 已合并 `main`：** 见 [PR #208](https://github.com/freejbgo/301/pull/208)。

**2026-10-17 #209 已合并 `main`：** 见 [PR #209](https://github.com/freejbgo/301/pull/209)。

**2026-10-17 #210 已合并 `main`：** 见 [PR #210](https://github.com/freejbgo/301/pull/210)。

**2026-10-17 #211 已合并 `main`：** 见 [PR #211](https://github.com/freejbgo/301/pull/211)。

**2026-10-17 #212 已合并 `main`：** 见 [PR #212](https://github.com/freejbgo/301/pull/212)。

**2026-10-17 #213 已合并 `main`：** 见 [PR #213](https://github.com/freejbgo/301/pull/213)。

**2026-10-17 #214 已合并 `main`：** 见 [PR #214](https://github.com/freejbgo/301/pull/214)。

**2026-10-17 #215 已合并 `main`：** 见 [PR #215](https://github.com/freejbgo/301/pull/215)。

**2026-10-17 #216 已合并 `main`：** 见 [PR #216](https://github.com/freejbgo/301/pull/216)。

**2026-10-17 #217 已合并 `main`：** 见 [PR #217](https://github.com/freejbgo/301/pull/217)。

**2026-10-17 #218 已合并 `main`：** 见 [PR #218](https://github.com/freejbgo/301/pull/218)。

**2026-10-17 #219 已合并 `main`：** 见 [PR #219](https://github.com/freejbgo/301/pull/219)（含 #211–#219 抽检与 `reconcileCertDeployStatus`）。

**2026-10-17 #220 已合并 `main`：** 见 [PR #220](https://github.com/freejbgo/301/pull/220)（#145–#220 复核纪要；`refuseQueueDeploy` reconcile）。

**2026-10-17 #221 已合并 `main`：** 见 [PR #221](https://github.com/freejbgo/301/pull/221)。

**2026-10-17 #222 已合并 `main`：** 见 [PR #222](https://github.com/freejbgo/301/pull/222)。

**2026-10-17 #223 已合并 `main`：** 见 [PR #223](https://github.com/freejbgo/301/pull/223)。

**2026-10-17 #224 已合并 `main`：** 见 [PR #224](https://github.com/freejbgo/301/pull/224)。

**2026-10-17 #225 已合并 `main`：** 见 [PR #225](https://github.com/freejbgo/301/pull/225)。

**2026-10-17 #226 已合并 `main`：** 见 [PR #226](https://github.com/freejbgo/301/pull/226)。

**2026-10-17 #227 已合并 `main`：** 见 [PR #227](https://github.com/freejbgo/301/pull/227)。

**2026-10-17 #228 已合并 `main`：** 见 [PR #228](https://github.com/freejbgo/301/pull/228)。

**2026-10-17 #229 已合并 `main`：** 见 [PR #229](https://github.com/freejbgo/301/pull/229)。

**2026-10-17 #230 已合并 `main`：** 见 [PR #230](https://github.com/freejbgo/301/pull/230)。

**2026-10-17 #231 已合并 `main`：** 见 [PR #231](https://github.com/freejbgo/301/pull/231)。

**2026-10-17 #232 已合并 `main`：** 见 [PR #232](https://github.com/freejbgo/301/pull/232)。

**2026-10-17 #233 已合并 `main`：** 见 [PR #233](https://github.com/freejbgo/301/pull/233)。

**2026-10-17 #234 已合并 `main`：** 见 [PR #234](https://github.com/freejbgo/301/pull/234)。

**2026-10-17 #235 已合并 `main`：** 见 [PR #235](https://github.com/freejbgo/301/pull/235)。

**2026-10-17 #236 已合并 `main`：** 见 [PR #236](https://github.com/freejbgo/301/pull/236)。

**2026-10-17 #237 已合并 `main`：** 见 [PR #237](https://github.com/freejbgo/301/pull/237)。

**2026-10-17 #238 已合并 `main`：** 见 [PR #238](https://github.com/freejbgo/301/pull/238)。

**2026-10-17 #239 已合并 `main`：** 见 [PR #239](https://github.com/freejbgo/301/pull/239)。

**2026-10-17 #240 已合并 `main`：** 见 [PR #240](https://github.com/freejbgo/301/pull/240)。

**2026-10-17 #241 已合并 `main`：** 见 [PR #241](https://github.com/freejbgo/301/pull/241)。

**2026-10-17 #242 已合并 `main`：** 见 [PR #242](https://github.com/freejbgo/301/pull/242)。

**2026-10-17 #243 已合并 `main`：** 见 [PR #243](https://github.com/freejbgo/301/pull/243)。

**2026-10-18 #244 已合并 `main`：** 见 [PR #244](https://github.com/freejbgo/301/pull/244)。

**2026-10-18 #245 已合并 `main`：** 见 [PR #245](https://github.com/freejbgo/301/pull/245)。

**2026-10-18 #246 已合并 `main`：** 见 [PR #246](https://github.com/freejbgo/301/pull/246)。

**2026-10-18 #247 已合并 `main`：** 见 [PR #247](https://github.com/freejbgo/301/pull/247)。

**2026-10-18 #248 已合并 `main`：** 见 [PR #248](https://github.com/freejbgo/301/pull/248)。

**2026-10-18 #249 已合并 `main`：** 见 [PR #249](https://github.com/freejbgo/301/pull/249)。

**2026-10-18 #250 已合并 `main`：** 见 [PR #250](https://github.com/freejbgo/301/pull/250)。

**2026-10-18 #251 已合并 `main`：** 见 [PR #251](https://github.com/freejbgo/301/pull/251)。

**2026-10-18 #252 已合并 `main`：** 见 [PR #252](https://github.com/freejbgo/301/pull/252)。

**2026-10-18 #253 已合并 `main`：** 见 [PR #253](https://github.com/freejbgo/301/pull/253)。

**2026-10-18 #254 已合并 `main`：** 见 [PR #254](https://github.com/freejbgo/301/pull/254)。

**2026-10-18 #255 已合并 `main`：** 见 [PR #255](https://github.com/freejbgo/301/pull/255)。

**2026-10-18 #256 已合并 `main`：** 见 [PR #256](https://github.com/freejbgo/301/pull/256)。

**2026-10-18 #257 已合并 `main`：** 见 [PR #257](https://github.com/freejbgo/301/pull/257)。

**2026-10-18 #258 已合并 `main`：** 见 [PR #258](https://github.com/freejbgo/301/pull/258)。

**2026-10-18 #259 已合并 `main`：** 见 [PR #259](https://github.com/freejbgo/301/pull/259)。

**2026-10-18 #260 已合并 `main`：** 见 [PR #260](https://github.com/freejbgo/301/pull/260)。

**2026-10-18 #261 已合并 `main`：** 见 [PR #261](https://github.com/freejbgo/301/pull/261)。

**2026-10-18 #262 已合并 `main`：** 见 [PR #262](https://github.com/freejbgo/301/pull/262)。

**2026-10-18 #263 已合并 `main`：** 见 [PR #263](https://github.com/freejbgo/301/pull/263)。

**2026-10-18 #264 已合并 `main`：** 见 [PR #264](https://github.com/freejbgo/301/pull/264)。

**2026-10-18 #265 已合并 `main`：** 见 [PR #265](https://github.com/freejbgo/301/pull/265)。

**2026-10-18 #266 已合并 `main`：** 见 [PR #266](https://github.com/freejbgo/301/pull/266)。

**2026-10-18 #267 已合并 `main`：** 见 [PR #267](https://github.com/freejbgo/301/pull/267)。

**2026-10-18 #268 已合并 `main`：** 见 [PR #268](https://github.com/freejbgo/301/pull/268)。

**2026-10-18 #269 已合并 `main`：** 见 [PR #269](https://github.com/freejbgo/301/pull/269)。

**2026-10-18 #270 已合并 `main`：** 见 [PR #270](https://github.com/freejbgo/301/pull/270)。

**2026-10-18 #271 已合并 `main`：** 见 [PR #271](https://github.com/freejbgo/301/pull/271)。

**2026-10-18 #272 已合并 `main`：** 见 [PR #272](https://github.com/freejbgo/301/pull/272)。

**2026-10-18 复核（#273，收口）：** 组合 `revision` 嵌套 SHA1 契约测；`/certificates` 返回 `force_https`；Go `tick` 仅拉变更分项；AUDIT 节点 revision 子域标记完成；PHPUnit 基线见 §9（以 preflight 为准）。

**2026-10-18 复核（#272）：** Feature 测 `register` 后 `certificates_revision` 与 `/certificates` 一致；ACME 挑战删除后 `acme-challenges` 为空；挑战域名 scheme 规范化；SECURITY 补充列表 `id` 顺序；PHPUnit **342**（**709** assertions）。

**2026-10-18 复核（#271）：** `certificates` 按 `id` 排序；`redirect-rules` 规范化 `https://` 主机名；Feature 测证书/挑战列表 id 顺序；PHPUnit **340**（**703** assertions）。

**2026-10-18 复核（#270）：** `acme-challenges` 按 `id` 排序；Feature 测 revision bump 与 `redirect-rules`/`acme-challenges` 载荷一致（含 `force_https`、过期挑战省略）；PHPUnit **337**（**699** assertions）。

**2026-10-18 复核（#269）：** `redirect-rules` 按 `id` 排序且与 `rules_revision` 过滤一致（停用域名、HTTP-01 token 格式非法不入 revision/`acme-challenges`）；PHPUnit **332**（**686** assertions）。

**2026-10-18 复核（#268）：** Feature 测 ACME token/域名与规则 `original_url` 含控制字符不入 revision 哈希；PHPUnit **329**（**677** assertions）。

**2026-10-18 复核（#267）：** Feature 测 ACME 响应体含控制字符不入 `challenges_revision`；合法 `original_url` 改为 IP 后 `rules_revision` 变化；PHPUnit **326**（**665** assertions）。

**2026-10-18 复核（#266）：** Feature 测域名重新启用与 `original_url` 主机名变更 bump `rules_revision`；PHPUnit **324**（**657** assertions）。

**2026-10-18 复核（#265）：** Feature 测就绪 ACME 挑战删除/过期 bump `challenges_revision`；已过期挑战不入 revision；PHPUnit **322**（**647** assertions）。

**2026-10-18 复核（#264）：** Feature 测非法/停用域名不入 `rules_revision`；就绪 ACME 挑战更新 bump `challenges_revision`；PHPUnit **319**（**635** assertions）。

**2026-10-18 复核（#263）：** Feature 测启用域名增删 bump `rules_revision` 与组合 `revision`（无证书分配时证书 revision 不变）；PHPUnit **316**（**621** assertions）。

**2026-10-18 复核（#262）：** Feature 测就绪 ACME 挑战入库 bump `challenges_revision` 与组合 `revision`（证书/规则不变）；不可发布挑战不入 revision；SECURITY 补充 `rules_revision`/`challenges_revision`；PHPUnit **314**（**611** assertions）。

**2026-10-18 复核（#261）：** Feature 测 `force_https` 切换同时 bump `rules_revision`、`certificates_revision` 与组合 `revision`；PHPUnit **312**（**601** assertions）。

**2026-10-18 复核（#260）：** Feature 测他节点失败后成功 ack 清除 `cert_last_error` 并 bump 本节点 revision；Feature 测成功 ack 清除陈旧部署 `last_error` 后 `deployment_error` revision 变化；PHPUnit **311**（**598** assertions）。

**2026-10-18 复核（#259）：** `markNodeAck` 在 `ok` 且部署行已为 `ok` 时清除陈旧 `last_error`；Feature 测他节点失败重排队后本节点 revision 变化；PHPUnit **309**（**590** assertions）。

**2026-10-18 复核（#258）：** Feature 测他节点失败 ack 后本节点 `ok` 部署仍因 `cert_last_error` bump revision；SECURITY 补充说明；PHPUnit **307**（**583** assertions）。

**2026-10-18 复核（#257）：** `certificates_revision` 含本节点 `cert_last_error`（证书 `last_error` SHA1）；单测锁定本节点 `ok` 时证书提示文案变更亦 bump revision；PHPUnit **306**（**578** assertions）。

**2026-10-18 复核（#256）：** `markNodeAck` 在部署行已为 `failed` 时仍接受新 `error` 并落库；Feature 测重复失败 ack 后 `certificates_revision` 变化；PHPUnit **305**（**577** assertions）。

**2026-10-18 复核（#255）：** `certificates_revision` 含本节点 `deployment_error`（部署 `last_error` SHA1）；单测锁定同状态仅错误文案变更亦 bump revision；PHPUnit **303**（**571** assertions）。

**2026-10-18 复核（#254）：** `certificates_revision` 含本节点 `cert_status`；Feature 测 ack 失败（pending→failed）后 revision 变化；PHPUnit **302**（**570** assertions）。

**2026-10-18 复核（#253）：** Feature 测 `ack` 成功（pending→ok）后 `certificates_revision` 变化；PHPUnit **300**（**565** assertions）。

**2026-10-18 复核（#252）：** 域名重新启用走 `freshCertWithPersistedRealign` 再 `queueDeploy`；`certificates_revision` 含本节点 `deployment_status`；Feature 测 requeue 后 revision 变化；PHPUnit **299**（**562** assertions）。

**2026-10-18 复核（#251）：** `requeueFailedDeployments` 跳过不可入队证书时清理 failed 部署行并 reconcile；PHPUnit **296**（**557** assertions）。

**2026-10-18 复核（#250）：** 单测锁定 sync 先清陈旧 `ok` 再为可拉取证书建 pending；PHPUnit **295**（**555** assertions）。

**2026-10-18 复核（#249）：** `syncDeploymentsForNode` 先 prune 再补齐 pending；Feature 测本节点部署行删除后 revision 变化；PHPUnit **294**（**553** assertions）。

**2026-10-18 复核（#248）：** `GET /api/nodes/revision` 与拉证前同样执行 `pruneStaleDeploymentsForNode`；Feature 测无 heartbeat 亦可清理陈旧 `ok` 行；PHPUnit **293**（**552** assertions）。

**2026-10-18 复核（#247）：** Feature 测域名停用/清 PEM 后 `certificates_revision` 下降；SECURITY 补充 PEM 变更清部署行；PHPUnit **292**（**550** assertions）。

**2026-10-18 复核（#246）：** `pruneNonPullableDeployments` 与节点侧一致，清除不可拉取证书的全部部署行；PHPUnit **290**（**547** assertions）。

**2026-10-18 复核（#245）：** `pruneStaleDeploymentsForNode` 清理不可拉取证书的全部部署行（含 `ok`）；`register` 后 `certificates_revision` 变化 Feature 测；PHPUnit **290**（**546** assertions）。

**2026-10-18 复核（#244）：** `certificates_revision` 含 `force_https`，避免仅改跳转策略时节点内存证书仍强制 HTTPS；PHPUnit **288**（**543** assertions）。

**2026-10-17 复核（#243）：** `certificates_revision` 哈希含加密 PEM 指纹，续期/轮换密文时即使 `updated_at` 不变也会触发节点拉证；PHPUnit **287**（**542** assertions）。

**2026-10-17 复核（#242）：** `certificates_revision` 按认证节点过滤部署行，与 `/certificates` 范围一致；SECURITY 补充 revision 说明；PHPUnit **286**（**541** assertions）。

**2026-10-17 复核（#241）：** 修复 `certificates_revision` 在 `certIsNodePullable` 前未加载 PEM 列导致哈希恒为空；单测覆盖新增部署行后 revision 变化；PHPUnit **284**（**539** assertions）。

**2026-10-17 复核（#240）：** health 漂移自愈复用 helper；SECURITY 对齐策略；复核表门禁数更新；PHPUnit **283**（**538** assertions）。

**2026-10-17 复核（#239）：** ACME `assertDomainIssuable` 与后台改域名后证书行复用 `freshCertWithPersistedRealign`；PHPUnit **283**（**538** assertions）。

**2026-10-17 复核（#238）：** `queueDeploy` / `redi:renew-certificates` 复用 `freshCertWithPersistedRealign`；PHPUnit **283**（**538** assertions）。

**2026-10-17 复核（#237）：** `freshCertWithPersistedRealign` 统一 sync/拉证落库对齐；health 漂移先只读 realign 再按需落库；PHPUnit **282**（**535** assertions）。

**2026-10-17 复核（#236）：** `certIsNodePullable` 只读 realign（health/节点拉取不再误写库）；PHPUnit **282**（**535** assertions）。

**2026-10-17 复核（#235）：** `certIsEligibleForDeployQueue` 只读 realign；`requeueFailedDeployments` 入队前显式落库 realign；PHPUnit **281**（**533** assertions）。

**2026-10-17 复核（#234）：** `deployableCertsForNodes` 列表阶段仅内存 realign；Feature 测 revision 忽略无部署行证书；PHPUnit **280**（**531** assertions）。

**2026-10-17 复核（#233）：** `certificates_revision` 仅含已有部署行的可拉取证书，减少 Agent 空转；PHPUnit **279**（**530** assertions）。

**2026-10-17 复核（#232）：** `syncDeploymentsForNode` / `requeueFailedDeployments` 结束后 reconcile；在线节点 register 同步 issued→deploying 单测；PHPUnit **278**（**529** assertions）。

**2026-10-17 复核（#231）：** `queueDeploy` 结束后 `reconcileCertDeployStatus`；无启用节点时清理僵尸部署行单测；PHPUnit **277**（**527** assertions）。

**2026-10-17 复核（#230）：** 无部署行时 `active` 亦 reconcile 为 `issued`；多节点停用单节点仍保留 active 单测；PHPUnit **276**（**523** assertions）。

**2026-10-17 复核（#229）：** 节点停用 `handleNodeDisabled` 回写证书状态（避免无部署行仍 active/deploying）；PHPUnit **274**（**520** assertions）。

**2026-10-17 复核（#228）：** `pruneStaleDeploymentsForNode` 后 `reconcileCertDeployStatus`；无部署行时 `deploying` 降为 `issued`；PHPUnit **273**（**516** assertions）。

**2026-10-17 复核（#227）：** Feature 测 `heartbeat` 清理不可拉取 pending；SECURITY 节点心跳说明；PHPUnit **273**（**514** assertions）。

**2026-10-17 复核（#226）：** `certIsNodePullableMemo` DRY；节点 `heartbeat` 亦 `pruneStaleDeploymentsForNode`；PHPUnit **272**（**512** assertions）。

**2026-10-17 复核（#225）：** health 部署计数按 `cert_id` 缓存 `certIsNodePullable`；节点证书 API 去掉重复 realign；PHPUnit **272**（**512** assertions）。

**2026-10-17 复核（#224）：** `realign` 增加 `$persist`；`NodeAgentRevisionService` 仅内存对齐，避免每 5s revision 写库+reconcile；PHPUnit **272**（**512** assertions）。

**2026-10-17 复核（#223）：** `refuseQueueDeploy` 复用 `afterCertMaterialOrHostChange`；PHPUnit **271**（**511** assertions）。

**2026-10-17 复核（#222）：** `afterCertMaterialOrHostChange` 统一 prune+reconcile；realign 漂移修正走同一路径；PHPUnit **271**（**511** assertions）。

**2026-10-17 复核（#221）：** `realignCertDomainNameFromAdminDomain` 使用 `saveQuietly` + 显式 reconcile；PHPUnit **271**（**511** assertions）。

**2026-10-17 复核（#220）：** `refuseQueueDeploy` 在写入跳过原因前先 reconcile，避免残留 active；SECURITY 证书队列说明；PHPUnit **271**（**511** assertions）。

**2026-10-17 复核（#211–#219 合并前抽检）：** 证书部署链路一致；补 `reconcileCertDeployStatus`（PEM/主机名变更后自动回写 status，避免残留 active）；约定「继续」流程见上文。

**2026-10-17 复核（#219）：** `refreshCertStatusFromDeployments` 仅在 `certIsNodePullable` 时标为 active；`reconcileCertDeployStatus`；PHPUnit **271**（**511** assertions）。

**2026-10-17 复核（#218）：** `markNodeAck` 部署行未变时仍 `refreshCertStatusFromDeployments`（重复 ack 可从 deploying→active）；PHPUnit **269**（**507** assertions）。

**2026-10-17 复核（#217）：** 证书 `domain_name`/PEM 变更与解密失败时 `pruneNonPullableDeployments`（不因 `status` 变更误删 failed 行）；PHPUnit **269**（**506** assertions）。

**2026-10-17 复核（#216）：** 域名 `original_url` 变更触发 `pruneNonPullableDeployments`；节点 `GET /certificates` 前 `pruneStaleDeploymentsForNode`；PHPUnit **268**（**505** assertions）。

**2026-10-17 复核（#215）：** `pruneNonPullableDeployments`：`queueDeploy` 拒绝与节点 `syncDeployments` 时清除不可拉取证书的 pending/failed 行；PHPUnit **266**（**503** assertions）。

**2026-10-17 复核（#214）：** 域名停用：凡停用前仍有部署行（含 failed 等）均写入 `DISABLE_DEPLOY_CANCEL_MSG`；PHPUnit **266**（**503** assertions）。

**2026-10-17 复核（#213）：** 域名停用时对「已签发但仍有待部署行」的证书写入 `DISABLE_DEPLOY_CANCEL_MSG`；PHPUnit **265**（**501** assertions）。

**2026-10-17 复核（#212）：** `handleDomainReenabledFromDisabled` 集中 2→1 部署逻辑；`DISABLE_DEPLOY_CANCEL_MSG` 公开；Agent 空证书列表注释；PHPUnit **264**（**499** assertions）。

**2026-10-17 复核（#211）：** 仅 `status` 2→1 时自动 `queueDeploy`，避免新建即启用误排队；PHPUnit **263**（**497** assertions）。

**2026-10-17 复核（#210）：** 域名重新启用且证书可下发时自动 `queueDeploy`；PHPUnit **262**（**496** assertions）。

**2026-10-17 复核（#209）：** 域名重新启用时清除停用部署取消提示；停用/启用消息常量化；PHPUnit **261**（**495** assertions）。

**2026-10-17 复核（#208）：** 域名停用时将证书从 active/deploying 降为 issued 并写明原因；PHPUnit **260**（**494** assertions）。

**2026-10-17 复核（#207）：** 节点停用删除其 `redi_cert_deployments` 并刷新 health 指标缓存；SECURITY 域名停用说明同步；PHPUnit **260**（**492** assertions）。

**2026-10-17 复核（#206）：** 部署变更后 `forgetActionableDeploymentMetrics` 刷新 health 缓存；单条重下发与批量同用 `certIsEligibleForDeployQueue`；PHPUnit **259**（**491** assertions）。

**2026-10-17 复核（#205）：** 域名停用清除 `redi_cert_deployments`；批量/单条重下发先 `certIsEligibleForDeployQueue`；节点 `certificates_revision` 与可拉取证书一致；PHPUnit **258**（**490** assertions）。

**2026-10-17 复核（#204）：** `requeueFailedDeployments` 仅启用节点/启用域名；后台部署计数忽略停用节点；health 部署指标单次聚合；`syncDeploymentsForNode` 用 `certIsNodePullable`；PHPUnit **257**（**489** assertions）。

**2026-10-17 复核（#203）：** `queueDeploy` 无 PEM/停用域名不再写入 pending 部署行；`markNodeAck` 拒已停用节点；PHPUnit **255**（**486** assertions）。

**2026-10-17 复核（#202）：** `countActionableDeployments` 统一 health `issues`/`summary` 与后台失败计数；`cert_deploy_failed` 忽略不可拉取证书的僵尸 failed；PHPUnit **252**（**476** assertions）。

**2026-10-17 复核（#201）：** `certIsNodePullable` 统一节点拉证/health/后台 pending 统计；`cert_deploy_pending` 不再误报僵尸 pending；PHPUnit **250**（**471** assertions）。

**2026-10-17 复核（#200）：** `markNodeAck` 拒停用域名；`purgeForHosts` 直查 + 遗留键；签发后感知 `queueDeploy` 失败；批量重下发无效域名单测；PHPUnit **248**（**467** assertions）。

**2026-10-17 #198 已合并 `main`：** 见 [PR #198](https://github.com/freejbgo/301/pull/198)。

**2026-10-17 复核（#199）：** `queueDeploy` 返回是否入队；批量重下发统计修正；`markNodeAck` 拒无 PEM 的成功 ack；`isNodePublishableHost` 容忍 scheme；health 漂移用 `certMatchesAdminDomain`；PHPUnit **246**（**462** assertions）。

**2026-10-17 #197 已合并 `main`：** 见 [PR #197](https://github.com/freejbgo/301/pull/197)（#187–#196 收敛复核文档 + Go HTTP-01 Host 对齐）。

**2026-10-17 复核（#198）：** `requeueFailedDeployments` 仅重试可下发且域名一致的证书；Agent 拉取 redirect/证书用 `AcmeHostKey`；PHPUnit **246**（**460** assertions）。

**2026-10-17 #196 已合并 `main`：** 见 [PR #196](https://github.com/freejbgo/301/pull/196)。

**2026-10-17 合并后集中复核（#197）：** 见下方 §「#187–#196 主机名/ACME 收敛复核」；Go `ResponseForPath` 与 store 同用 `AcmeHostKey`；门禁全绿。

**2026-10-17 #195 已合并 `main`：** 见 [PR #195](https://github.com/freejbgo/301/pull/195)。

**2026-10-17 复核（#196）：** ACME 授权域名 punycode 对齐；节点 `ack` 拒绝域名漂移；停用/删域名 purge 传原始 URL；prune 畸形行按 id 删；Go `AcmeHostKey`；PHPUnit **245**（**458** assertions）。

**2026-10-17 #194 已合并 `main`：** 见 [PR #194](https://github.com/freejbgo/301/pull/194)。

**2026-10-17 复核（#195）：** HTTP-01 等待认可遗留 scheme 的节点 sync 行；`purgeForHosts` 支持精确存储键；`queueDeploy` 漂移/无效域名不排队；PHPUnit **244**（**454** assertions）。

**2026-10-17 #193 已合并 `main`：** 见 [PR #193](https://github.com/freejbgo/301/pull/193)。

**2026-10-17 复核（#194）：** `purgeForHosts` 按 `acmeHostKey` 清遗留 scheme 键；挑战 `upsert` 规范化并去重；`prune-acme-challenges` 同步清理走 `purgeForHosts`；签发 Job 失败清理含 `original_url`；PHPUnit **241**（**448** assertions）。

**2026-10-17 #192 已合并 `main`：** 见 [PR #192](https://github.com/freejbgo/301/pull/192)。

**2026-10-17 复核（#193）：** `AcmeChallengeQuery` 可下发行用 `acmeHostKey`（兼容带 scheme 的脏 `domain_name`）；后台改域名与签发成功走 `purgeForHosts`；PHPUnit **240**（**446** assertions）。

**2026-10-17 #191 已合并 `main`：** 见 [PR #191](https://github.com/freejbgo/301/pull/191)。

**2026-10-17 复核（#192）：** `DomainRecordNormalizer::acmeHostKey` 统一 ACME 白名单/下发/ revision / HTTP-01 等待；删域名 canonical 清挑战；PHPUnit **239**（**444** assertions）。

**2026-10-17 #190 已合并 `main`：** 见 [PR #190](https://github.com/freejbgo/301/pull/190)。

**2026-10-17 复核（#191）：** `challenges-synced` / ACME 清理用 `originalUrlIfValid`；停用域名 canonical 清挑战；后台保存证书行 `realign`；PHPUnit **238**（**442** assertions）。

**2026-10-17 #189 已合并 `main`：** 见 [PR #189](https://github.com/freejbgo/301/pull/189)。

**2026-10-17 复核（#190）：** 节点拉证/revision 拉取前 realign；后台改域名时 ACME 清理用 canonical 主机名；移除无用 `certHostIsReportable`；PHPUnit **236**（**438** assertions）。

**2026-10-17 #188 已合并 `main`：** 见 [PR #188](https://github.com/freejbgo/301/pull/188)。

**2026-10-17 复核（#189）：** health 漂移检测先 `realign`；`requeue`/节点 `ack` 与 ACME `assertDomainIssuable` 同步纠偏；PHPUnit **235**（**436** assertions）。

**2026-10-17 #187 已合并 `main`：** 见 [PR #187](https://github.com/freejbgo/301/pull/187)。

**2026-10-17 复核（#188）：** `realignCertDomainNameFromAdminDomain` 在部署/续期自动纠偏；health 新增 `cert_domain_name_drift`；到期告警按域名 canonical 展示；PHPUnit **234**（**434** assertions）。

**2026-10-17 #186 已合并 `main`：** 见 [PR #186](https://github.com/freejbgo/301/pull/186)。

**2026-10-17 复核（#187）：** `certMatchesAdminDomain` 防证书行与域名漂移误下发；续期前自动对齐 `domain_name`；节点规则用 `originalUrlIfValid` 兼容遗留 URL；PHPUnit **232**（**430** assertions）。

**2026-10-17 #185 已合并 `main`：** 见 [PR #185](https://github.com/freejbgo/301/pull/185)。

**2026-10-17 复核（#186）：** 后台/批量/CSV/模型保存全面改用 `originalUrlIfValid`；签发 Job 先算可发布主机名；`CertificateIssuer` 签发前校验 cert 行主机名。

**2026-10-17 复核（#185）：** `DomainRecordNormalizer::originalUrlIfValid` 统一规范化+校验；签发 Job / `CertificateIssuer` / DNS 守卫去重；PHPUnit **230**（**425** assertions）。

**2026-10-17 #184 已合并 `main`：** 见 [PR #184](https://github.com/freejbgo/301/pull/184)。

**2026-10-17 复核（#184）：** `isValidOriginalHost` 单次规范化后校验；CNAME 预检 / CSV 导出 / 占坑守卫 / `redi:normalize-domain-hosts` 对齐。

**2026-10-17 #183 已合并 `main`：** 见 [PR #183](https://github.com/freejbgo/301/pull/183)。

**2026-10-17 复核（#183）：** 签发 Job / ACME 清理 / DNS 启用守卫与后台域名保存统一 `isNodePublishableHost` / `isValidOriginalHost`。

**2026-10-17 #182 已合并 `main`：** 见 [PR #182](https://github.com/freejbgo/301/pull/182)。

**2026-10-17 复核（#182）：** ACME/健康/域名规范化链路复用 `isNodePublishableHost`；`challenges-synced` 校验与之一致。

**2026-10-17 复核（#181）：** `HostNameValidator::isNodePublishableHost` 统一节点/部署/续期主机名校验；PHPUnit **230**（+1）。

**2026-10-17 复核（#180）：** `deployableCertsForNodes` 与 challenges revision 排除控制字符域名；PHPUnit **229**（+1）。

**2026-10-17 复核（#179）：** 节点 `revision` 的证书/规则哈希排除含控制字符的域名；PHPUnit **228**（+2）。

**2026-10-17 复核（#178）：** 节点 `revision` 与 `redi:prune-acme-challenges` 改用 `nodePublishableRow`；PHPUnit **226**（+2）。

**2026-10-17 复核（#177）：** `AcmeChallengeQuery::nodePublishableRow` 统一节点挑战下发规则；`challenges-synced` 拒 DB 内 token 污染；Go 规则拉取变量命名整理；PHPUnit **224**（+2）。

**2026-10-17 复核（#176）：** ACME 挑战 token/response_body 控制字符过滤；Go agent 拉取规则/证书时拒污染域名；PHPUnit **222**（+1）。

**2026-10-17 复核（#175）：** ACME 挑战列表/`challenges-synced` 白名单显式拒控制字符域名；Go agent 拉取挑战时先校验再写入内存；PHPUnit **221**（+1，Go 逻辑整理）。

**2026-10-17 复核（#174）：** 节点 `redirect-rules`/`certificates` 下发前过滤含控制字符的域名；`ack` 缺 `cert_id` 拒 422；PHPUnit **220**（+3）。

**2026-10-17 复核（#173）：** `ack` 严格校验 `cert_id`；`ok=true` 时忽略 `error`；`challenges-synced` 要求 `domains` 为数组；PHPUnit **217**（+3）。

**2026-10-17 复核（#172）：** `ack` 拒非字符串 `error`（422）；心跳 `version` 尾部换行全栈拒 426；PHPUnit **214**（+2）。

**2026-10-17 复核（#171）：** 节点 `ack` 用 `Request::boolean` 解析 `ok`（修复 `"false"` 被当成功）；`NodeAgentInputFields` 统一中间件豁免列表；PHPUnit **212**（+1）。

**2026-10-17 复核（#170）：** `TrimStrings`/`ConvertEmptyStringsToNull` 对 `domains.*` 等用 `Str::is` 豁免；节点 agent 不上报含控制字符的 challenge domain；PHPUnit **211**（+2）。

**2026-10-17 复核（#169）：** `challenges-synced` 跳过 `null`/非字符串 domain 项（不计入 ignored）；register version 含控制字符拒 426；masteragent `User-Agent: redi-multi/<version>`；PHPUnit **209**（+2，Go +1）。

**2026-10-17 复核（#168）：** `TrimStrings` 豁免节点 `version`/`error`；`ack` 含控制字符的 `error` 拒 422；心跳 version 含 `\0` 全栈拒 426；PHPUnit **207**（+1）。

**2026-10-17 复核（#167）：** `challenges-synced` 显式拒 domain 控制字符；`TrimStrings` 不 trim `domains`；`TrustProxies` 列表过滤；Echo 限流 `SanitizeClientIP`；PHPUnit **206**（+1）。

**2026-10-17 复核（#166）：** API/节点限流 IP 键拒控制字符；ACME `writePlain` 响应体 `StripControlChars`；PHPUnit **205**（Go +1）。

**2026-10-17 复核（#165）：** `challenges-synced` 422 回显 `field()`；`extra_ips` 保存净化；redirect `Location` 头防控制字符；PHPUnit **205**（+1，Go +1）。

**2026-10-17 复核（#164）：** redirect 仅接受 **GET**；`RediNode.public_ip` 保存净化；节点 API 限流键忽略含控制字符的 Bearer；PHPUnit **204**（+1，Go +1）。

**2026-10-17 复核（#163）：** 节点 `REDI_*_LISTEN` 环境变量拒控制字符；redirect 整行请求拒控制字符；`parseExtraIps` / `RediNode` 保存逻辑整理；PHPUnit **203**（+1，Go +1）。

**2026-10-17 复核（#162）：** `config.json` 字符串字段解析拒控制字符；redirect 请求头拒控制字符；节点 Bearer/名称/分组名保存净化；PHPUnit **202**（+2，Go +1）。

**2026-10-17 复核（#161）：** 节点 `config.json` 监听/跳转字段拒控制字符；redirect 请求行拒控制字符；`RediNode` 备注保存净化；PHPUnit **200**（+1）。

**2026-10-17 复核（#160）：** `RediOpsWebhook::normalizeWebhookUrl` 统一配置与显式 URL；`REDI_DB_TLS` / `REDI_API_LISTEN` 拒控制字符；PHPUnit **199**（+1）。

**2026-10-17 复核（#159）：** `WriteAsset` 拒含控制字符的 URI；节点 `REDI_DB_*` DSN 字段校验；webhook 配置 URL 拒控制字符；PHPUnit **198**（Go +1，+1）。

**2026-10-17 复核（#158）：** `redirecturl.IsAllowed` / `Decoded.Encode` 拒控制字符；Agent 启动校验 `REDI_MASTER_URL`/`REDI_NODE_TOKEN`；后台分组名与 CNAME 展示 `field()`；PHPUnit **197**（Go +2）。

**2026-10-17 复核（#157）：** `domain.Encoded.Decode` 拒控制字符；Agent 主控错误响应日志 `LogSnippet`；后台节点/分组/域名列表名称类列 `field()`；PHPUnit **197**（Go +1）。

**2026-10-17 复核（#156）：** Go `host.StripControlChars` / `LogSnippet`；Agent ack 错误复用；Echo JS 响应统一 `Content-Type`；redirect `remoteIP` 净化；后台域名列表 `original_url`/`skip_url` 展示 `field()`；PHPUnit **197**（Go +2）。

**2026-10-17 复核（#155）：** Go `host.SanitizeClientIP` 统一 `/c.js`、`/api.js` 限流键与 `cip` 字段（拒控制字符）；PHPUnit **197**（Go +2）。

**2026-10-17 复核（#154）：** `/api.js` 查询 `d` 含控制字符返回 **400**（不再误报 429）；`apiJsQueryValid` 统一校验；TLS 直连 `WriteAsset` 的 `/c.js` 也净化 UA；PHPUnit **197**（Go +2）。

**2026-10-17 复核（#153）：** Go `host.SanitizeUserAgent` 统一 Echo `/c.js` 与 redirect 路径；`persistAgentVersionIfPresent` 复用 `resolveFromInput`；PHPUnit **197**（Go +2）。

**2026-10-17 复核（#152）：** `RediAgentVersionGate::resolveFromInput` 统一版本解析；register 无 version 保留版本；redirect User-Agent / api.js 域名限流拒控制字符；PHPUnit **197**（+4）。

**2026-10-17 复核（#151）：** 节点 register/heartbeat 仅在 body 含有效 `version` 时更新 `agent_version`；空 version 拒 426；redirect 请求 Host 走 `sanitizeHost`；后台节点版本展示 `field()`；PHPUnit **193**（+2）。

**2026-10-17 复核（#150）：** ACME `ResponseForPath` 拒绝含控制字符的 path；`RejectOldRediAgent` 不因畸形 body version 回退节点版本；批量导入错误文案 `field()`；PHPUnit **191**（+2）。

**2026-10-17 复核（#149）：** ACME/Agent 版本号在 trim 前拒控制字符；Go `Http01TokenValid` + redirect 复用 `host.ContainsControlChars`；PHPUnit **189**（+3）。

**2026-10-17 复核（#148）：** Go `host.ContainsControlChars` 统一 SNI/规则查询；`redi:ops-webhook` 任务文案 `field()`；PHPUnit **186**（Go +2）。

**2026-10-17 复核（#147）：** Go rules/certs/redirect 在 trim 前拒控制字符；DNS/域名规范化复用 `OpsDisplaySanitizer`；health webhook 纯文本 `field()`；PHPUnit **186**（Go +3）。

**2026-10-17 复核（#146）：** Go `Http01ResponseBodyValid` 在 trim 前拒控制字符；HTTP-01 公网探测校验响应体；`HostNameValidator` 复用 `OpsDisplaySanitizer`；`redi:health` CLI 文案 `field()`；PHPUnit **186**（+1）。

**2026-10-17 复核（#145）：** HTTP-01 waiter / `CertificateIssuer` 统一 `http01ContentValid`；后台 health issue 展示截断；Go `ValidChallengeToken` + Agent 校验 token；PHPUnit **185**（+2）。

**2026-10-16 复核（#144）：** `AcmeChallengeQuery::nodeReadyRow` 集中节点挑战校验；`http01ContentValid` 在 trim 前检测控制字符（避免尾部换行绕过）；Go Agent 拉取挑战跳过控制字符响应体；后台证书 `last_error` 展示净化；PHPUnit **183**（+2）。

**2026-10-16 复核（#143）：** `AcmeChallengeQuery::http01ContentValid` 统一 challenges-sync / revision / prune；HTTP-01 waiter 拒绝控制字符响应体；PHPUnit **181**（+6）。

**2026-10-16 复核（#142）：** PHP ACME `upsertForDomain` / 节点 `acme-challenges` 与 Go 对齐，拒绝响应体控制字符；`OpsDisplaySanitizer::containsControlChars`；PHPUnit **175**（+2）。

**2026-10-16 复核（#141）：** Go `acme` 存储用 `CanonicalLookupHost` 且拒绝响应体控制字符；PHPUnit **173**（Go acme +2）。

**2026-10-16 复核（#140）：** Go 节点 host/redirect/certs/rules 拒绝 TAB；`collectHints` 文案净化；PHPUnit **173**（Go host/redirect +2）。

**2026-10-16 复核（#139）：** `collectIssues` 统一出口净化；TAB 控制字符与主机名/DNS/规范化一致拒绝；EdgeDnsSync 失败日志精简；PHPUnit **173**（assertions 314）。

**2026-10-16 复核（#138）：** 后台首页 health 列表净化；HTTP-01 超时文案；CNAME `last_error` 展示；health hints CLI 净化；PHPUnit **173**。

**2026-10-16 复核（#137）：** 部署 ack `last_error` 二次净化；批量导入错误文案；Go Agent ack error 净化；EdgeDnsSync 日志 zone；PHPUnit **173**（Go masteragent +2）。

**2026-10-16 复核（#136）：** 签发 Job / 后台 DNS 同步 / DNS reconcile 删除失败文案 `logSnippet`；平台凭证解密日志净化；PHPUnit **173**。

**2026-10-16 复核（#135）：** health JSON/CLI 统一 `sanitizeHealthIssues`；节点 ack `last_error` 净化；EdgeDnsSync 用户可见异常文案截断；PHPUnit **173**。

**2026-10-16 复核（#134）：** webhook 失败日志截断；POST payload issues 净化；证书 health 文案域名展示加固；prune 去重 domain 列表；PHPUnit **171**。

**2026-10-16 复核（#133）：** 抽取 `OpsDisplaySanitizer` 统一运营输出净化；EdgeDnsSync 日志用规范化 FQDN；ops/health webhook 文案去控制字符；PHPUnit **169**。

**2026-10-16 复核（#132）：** health 节点展示字段去控制字符；`redi:health` 终端截断 issue 文案；DNS `requireDnsFqdn` 拒绝控制字符；renew 跳过日志不落原始域名；PHPUnit **165**。

**2026-10-16 复核（#131）：** `EdgeDnsSyncService` Pool CNAME 文案；`redi:normalize-domain-hosts` 控制台不回显非法 URL；DNS `requireDnsRrLabel` 拒绝控制字符；PHPUnit **163**。

**2026-10-16 复核（#130）：** CNAME reconcile 错误文案与 DNS trait 对齐；`redi:renew-certificates` 控制台输出用规范化主机名、跳过项不打印原始非法串；PHPUnit **161**。

**2026-10-16 复核（#129）：** `ReconcilesDnsRecords` CNAME 目标校验；`RediHealthReport` 证书告警跳过非法 `domain_name`；Cloudflare CNAME/CSV 单测补强；PHPUnit **160**。

**2026-10-16 复核（#128）：** DNS 厂商 `EnsuresValidDnsNames`；`HostNameValidator` 拒绝控制字符；同步校验 pool CNAME 目标；CSV 导出 DNS 列跳过非法域名；PHPUnit **157**。

**2026-10-16 复核（#127）：** 后台 DNS 表单与 `TenantEdgeService` 统一 `HostNameValidator`；手动 DNS 参考表跳过非法 edge；`challenges-synced` 拒绝超长域名；Go redirect `sanitizeHost` punycode；PHPUnit **155**。

**2026-10-16 复核（#126）：** `RediPlatformDns::poolFqdn` 校验标签；HTTP-01 等待时间钳制 10–600s；CNAME 链/ skip_url CRLF；masteragent 挑战与规则用 `CanonicalLookupHost`；PHPUnit **152**。

**2026-10-16 复核（#125）：** `CertificateIssuer`/DNS 同步校验 pool 与租户 FQDN；域名规范化拒绝 CRLF；Go `host`/`masteragent` 证书域名规范化；PHPUnit **149**。

**2026-10-16 复核（#124）：** CNAME 目标/pool/A 解析预检加固；签发 Job 拒绝无效租户 edge；health webhook 最多 40 条 issues；Go `certs` 忽略 CRLF 域名；PHPUnit **146**。

**2026-10-16 复核（#123）：** CNAME 预检/DNS 启用守卫拒绝非法主机名；`AcmeChallengeCleanup` 仅清理合法标签；`config/redi.php` 钳制 health/ACME 阈值；Go `rules` 忽略 CRLF host；PHPUnit **144**。

**2026-10-16 复核（#122）：** 证书部署/续期跳过非法 `domain_name`；`RediAcmeChallenge::upsertForDomain` 校验主机名；health webhook 纯文本截断 4000；PHPUnit **141**。

**2026-10-16 复核（#121）：** `NodeAgentRevisionService` 与节点 API 同规则过滤主机名/ACME token；`acme-challenges` 用 `AcmeHttp01Token`；`SafeRelativeRedirect` 拒绝 CRLF；ops webhook 截断 task/message；PHPUnit **137**。

**2026-10-16 复核（#120）：** 节点 API `redirect-rules` / `acme-challenges` / `certificates` 过滤非法主机名；`collectIssues` `--days` 上限 365；DNS reconcile 复用 `filterValidIpv4List`；节点 ACME store 忽略空响应体；PHPUnit **135**。

**2026-10-16 复核（#119）：** DNS 厂商 pool A 统一 `filterValidIpv4List`；`redi:health`/renew `--days` 上限 365；后台 DNS 同步返回具体错误；PHPUnit **134**。

**2026-10-16 复核（#118）：** Cloudflare DNS 列表分页拉全；`CertificateIssuer` token 走 `AcmeHttp01Token`；PHPUnit **133**。

**2026-10-16 复核（#117）：** DNSPod/dns.com 列表分页上限；ACME 空响应体拒绝；租户 edge CNAME 目标 punycode；PHPUnit **132**。

**2026-10-16 复核（#116）：** `AcmeHttp01Token` 统一校验；签发 Job 拒绝无效主机名；挑战同步刷新心跳；节点 ACME store 拒绝畸形 token；PHPUnit **131**。

**2026-10-16 复核（#115）：** DNS A 记录 reconcile 忽略非法 IPv4；`node_pool_empty` health；ACME 挑战 upsert 校验 token/响应体；PHPUnit **129**。

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
| 自动化测试 | 本地：Go 全包测试通过；PHPUnit **248**；`bash -n` + `lagran_ports.py` + procedure 校验 |
| lagran 运维 | `lagran_ports.py`、diagnose、cleanup 脚本 |
| GitHub Actions | `ci.yml` 的 `centos7-scripts` 已跑 `verify-redi-db-deploy-path.sh`（需 Docker）；PR/推送若因 Runner/账单整 job 失败，请按 §9 本地复现 |

**未发现阻塞商用的 P0 代码缺陷。**

### #187–#196 主机名 / ACME / 证书收敛 — 合并后复核（#197）

多轮 PR **不是**「修完又坏」的循环，而是同一类生产隐患（`original_url` / 证书 `domain_name` / ACME 挑战键 / 节点 sync 行 **不一致**）分步收紧：每轮改动面小、带单测，便于回滚与审查。

| 复核项 | 方法 | 结论 |
|--------|------|------|
| 全量门禁 | `bash scripts/dev-preflight.sh`（Go + PHPUnit 248 + 脚本/SQL） | 通过 |
| 证书下发面 | `realign` + `certMatchesAdminDomain` 在节点 API、revision、deploy 队列、health | 路径一致；非法/漂移域名 **不下发、不 ack 成功** |
| ACME 面 | `acmeHostKey` / `purgeForHosts`（含遗留 scheme 与精确键） | 主控与节点 Go `AcmeHostKey` 对齐；HTTP-01 等待认可遗留 sync 键 |
| 行为变化（预期） | 后台域名无效时续期/部署/ack 会 **拒绝或跳过** 而非带错主机名继续 | 单测覆盖 `queueDeploy`、`markNodeAck`、`IssueDomainCertificateJob` 清理 |
| 仍建议真机验证 | 双节点、改 `original_url`、续期、停用域名 | `php artisan redi:health`；关注 `cert_domain_name_drift`、pending 部署 |
| 已知 P2 | `purgeForHosts` 全表扫描挑战/sync（表很小） | 规模极大时再优化 |

**回归风险判断：** 当前自动化未暴露逻辑冲突；剩余风险主要在 **真实 ACME / 公网 HTTP-01 / 多节点时序**，需按 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 抽测，而非继续堆小 PR。

### 全模块复核说明（每轮门禁 + #107 代码向补扫）

| 模块 | 每轮门禁 | #107 代码向处理 / 单测 |
|------|----------|-------------------------|
| **CentOS 脚本 / Cron** | `bash -n`、文档 `grep` | #101–#106 已合入栈（NODE_IPS、webhook、normalize 等） |
| **运营命令** | PHPUnit + CONSOLE | health/renew/prune/ops-webhook/requeue |
| **节点 API + Agent** | Feature `NodeAgentApiTest` + Go `masteragent` | challenges-synced、revision、证书 ack |
| **ACME 签发链** | 编译 + Job 队列单测 | `CertificateIssuer` 无授权域名时明确失败；签发失败 Job 与 Issuer 内 `AcmeChallengeCleanup`；`REDI_ACME_HTTP01_POLL_SECONDS`；`parseCertExpiry` / **`Http01PropagationWaiter`** 单测 |
| **DNS 厂商同步** | `EdgeDnsSyncServiceTest`、厂商 upsert 单测 | `isDnsApiMode()` 大小写归一；阿里云/DNSPod/dns.com 列表分页上限；`DnsProviderFactory` 未知驱动抛错 |
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

PHPUnit 当前 **417** 条 Feature/Unit（**893** assertions）；以 `phpunit` 退出码为准。GitHub Actions 配额有限时，在开发机执行 `bash scripts/dev-preflight.sh` 即可作为同等门禁。
