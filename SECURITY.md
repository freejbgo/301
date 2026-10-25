# 301 系统安全说明

**新手请先看 [DEPLOY.md](DEPLOY.md)**，按步骤部署即可。

## 已在代码/库里做好的事

- 数据库：绿场 **一次导入 `redi_admin.sql`**（含节点存储过程 `redi_node_bump_skip`）；默认 `admin` 密码见 DEPLOY.md，登录后强制改密；`original_url` **全平台唯一**。
- 主控：多租户隔离、**原始域名全平台唯一**（与边缘节点按 Host 查库一致）、跳转 URL 仅允许 `http/https`、`.env.example` 默认 `APP_DEBUG=false` / `SESSION_SECURE_COOKIE=true`。
- 节点：`REDI_DB_*` 环境变量；MySQL 账号 **`redi_node`** 仅可 **EXECUTE** 存储过程 `redi_node_bump_skip`（按 Host 返回 `skip_url` 并递增 `skip_num`，`SQL SECURITY DEFINER`；见 `grant-node-mysql.sh`），不能 `SELECT` 域名映射全表；`status=1` 才跳转计数；生产 **80/443** 与调试 **8080** 上 `/c.js`、`/api.js` 均 **按 IP 限速**（默认约 **120 次/分钟/IP**，两路径共用计数），`/api.js` 另有 **按 IP+域名参数** 限速（默认约 **60 次/分钟**，减轻刷 `skip_num`）；Echo 默认 **127.0.0.1:8080**；**不再清空整表 iptables OUTPUT**。
- `composer.json` 声明 Laravel 8 + Dcat Admin。CentOS 7 用发布包里的 `vendor`，不用在服务器上执行 `composer install`。

## 你必须在服务器上完成的

1. **修改所有默认密码**（后台 admin、MySQL、`.env` 的 `DB_PASSWORD`、节点 `env.sh`）。
2. **MySQL 不对公网开放 3306**（见 `db/mysql_setup.example.sql`；节点出口 IP 在主控用 `sudo MYSQL_ROOT_PASSWORD='…' bash scripts/centos7/grant-node-mysql.sh <IP> <DB_PASS>`，或 `sudo bash scripts/centos7/grant-nodes-from-vars.sh`；防火墙只放行这些 IP）。
3. **关闭 DNS 反查**：主控执行 `scripts/centos7/apply-mysql-skip-name-resolve.sh`。
4. **主控启用 HTTPS**（`ADMIN_HTTPS=true`，并且 Nginx 上的证书和 `APP_URL` 一致）。
5. **防火墙** 只开放 22/80/443。节点不必对访客开放 8080。不要执行 `iptables -F`。
6. **运营监控**：CentOS 由 `/etc/cron.d/redi-renew` 直调 artisan（**03:10** 续期、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`）；**勿**配置 `php artisan schedule:run`。配置 `REDI_HEALTH_WEBHOOK_URL` 后，Cron 的 `--notify` 在发送成功时退出 0（health 通知**仅**发往该 URL；`REDI_OPS_WEBHOOK_URL` 用于 Cron 行 `|| redi:ops-webhook` 等运维失败兜底）。亦可采集 `redi:health --json`。`issues[].code` / `hints[].code` 见 [docs/HEALTH-REFERENCE.md](docs/HEALTH-REFERENCE.md)；生产须关 `APP_DEBUG` 并配置 ACME 邮箱与 `edge_zone`。

## 边缘池与证书

| 项 | 说明 |
|----|------|
| 节点 API Token | 格式 `{节点ID}.{随机串}`，仅存 bcrypt 哈希；创建节点时**仅展示一次**。泄露或主机被控后须 **立即轮换 Token**（后台节点管理），并视情况吊销/重装证书；在启用节点上仍可能拿到 **已分配部署任务的全池证书** |
| `redirect-rules` | 认证节点可拉取 **全平台启用域名** 列表（无 `skip_url`），用于池内任意节点上 `force_https`；属元数据泄露面，依赖 Token 保密 |
| `TRUSTED_PROXIES=*` | 生产默认 **拒绝启动**（含 `queue:work` / `schedule:run`）；仅 `REDI_ALLOW_TRUST_ALL_PROXIES=true` 且主控绝不直连公网时例外 |
| `/api/nodes/certificates` | Bearer 鉴权；**仅返回本节点在 `redi_cert_deployments` 中有记录且可拉取的证书**（与 `ack` 一致；不可拉取时主控会清理该节点全部部署行，含历史 `ok`）。Token 泄露仍可拿到该节点被分配的全池证书，须轮换 Token 并隔离主机 |
| `/api/nodes/revision` | 与 `/certificates` 相同会先清理本节点不可拉取证书的部署行；`certificates_revision` 哈希含本节点 `deployment_status`、`deployment_error`（部署 `last_error` SHA1）、`cert_status`、`cert_last_error`（证书级 `last_error` SHA1）、域名 `force_https`、证书元数据与**加密 PEM 密文指纹**（非明文）；`rules_revision` / `challenges_revision` 分别哈希启用域名跳转策略与**可发布** ACME 挑战（空 token/非法 body 不计入），组合 `revision` 三者 SHA1 嵌套；续期、跳转策略、挑战或部署变更后 Agent 会拉取对应端点 |
| 节点列表端点顺序 | `redirect-rules`、`acme-challenges`、`/certificates` 与对应分项 revision 哈希均按表主键 `id` 升序返回，便于 Agent 做确定性 diff |
| 节点 `heartbeat` / `register` | 更新 `last_heartbeat_at`；`register` 会 `syncDeploymentsForNode`（先清理本节点陈旧部署行再补齐 pending）；`heartbeat`、拉证与 `revision` 亦会清理不可拉取证书的全部部署行（含历史 `ok`）并 reconcile |
| 节点 `ack` | 仅当存在该节点对应的 `redi_cert_deployments` 行且状态可更新时才接受；否则 **409**（避免伪造部署成功）；部署行 `pending`/`failed`/`ok` 变化会反映到本节点 `certificates_revision`；已为 `failed` 时再次 `ok=false` 且带新 `error` 会更新 `last_error`（驱动 `deployment_error` revision）；`ok=true` 且部署行已为 `ok` 时会清除陈旧 `last_error`；他节点失败/重排队导致证书级 `last_error` 变更时，本节点 `ok` 部署亦会通过 `cert_last_error` bump revision |
| `challenges-synced` | 非法 Host 返回 **422**；未在 ACME 就绪表中的域名返回 200 且计入 `ignored_count`（节点可区分同步失败与格式错误） |
| 平台 DNS 表单 | 仅超级管理员可提交（与「DNS 与节点池」页面一致） |
| DNS API 同步 | 厂商记录 **先增/改、后删**；Pool A 与租户 CNAME 分步执行。租户 `edge_fqdn` 不在平台 zone 下会 **中止**（全部无效时不写厂商；部分无效时 Pool/已写 CNAME 可能已更新）。任一步失败则 **不更新** `dns_synced_at`（需修正后重试）。`dns_mode=api` 时未知 `dns_driver` 会 **抛错**（不会静默退回手动模式） |
| 域名停用 | `status=2` 时清除该域 ACME 挑战、节点 sync 行及 `redi_cert_deployments`，证书 active/deploying 降为 issued 并写明取消部署；**任意状态**下若停用前仍有部署行则写入同一取消提示；从停用 **改回** `status=1` 且 PEM 可用时会自动 `queueDeploy`（非新建即启用） |
| Session Cookie | HTTPS 后台请设 `SESSION_SECURE_COOKIE=true`（见 `.env.example`） |
| 证书存储 | `redi_domain_certs` 中 PEM 经 Laravel `Crypt` 加密；备份库即备份密钥材料 |
| ACME | 主控 `storage/app/acme` 存 LE 账户；签发前要求节点上报挑战已同步，并探测公网域名 HTTP-01 |
| 节点注册 | Agent 不能通过 API 修改节点公网 IP；IP 只在后台「节点管理」维护 |
| 节点停用 | 后台关闭 `enabled` 时删除该节点全部 `redi_cert_deployments` 并回写受影响证书状态（无剩余部署行时 active/deploying 降为 issued）；Agent 鉴权亦会拒绝 |
| 证书队列 | 子账号仅能为本人名下域名入队；**超级管理员**可为任意租户域名入队；同一时刻只跑一个 ACME 任务；PEM/主机名漂移或 `queueDeploy` 拒绝时会清理不可拉取的 pending/failed 行并 **reconcile** 证书状态（避免后台仍显示「已生效」） |
| 证书 `domain_name` 对齐 | 扫描类路径（`certIsNodePullable`、部署资格检查）仅内存对齐；**落库**对齐经 `freshCertWithPersistedRealign`（sync/拉证/入队/续期/签发/health 自愈）；节点 `ack` 在行锁内对齐 |
| 证书 PEM / 主机名变更 | 模型 `saved` 钩子触发 `afterCertMaterialOrHostChange`：不可拉取时删除**全部** `redi_cert_deployments` 并 reconcile（与节点 heartbeat 清理一致） |
| 队列 | 生产请 `QUEUE_CONNECTION=database`，并常驻 `queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600`（CentOS `deploy-master` / `redi-queue.service` 已写入） |
| ACME 并发 | 全局锁 `redi-acme-le-global` + 按域名锁；**多台** `queue:work` 时 `CACHE_DRIVER` 须为 **redis**（或共享缓存），否则文件锁不跨机 |
| 节点 Agent | `REDI_MASTER_URL` 必须 **https://**；可选 `REDI_DB_TLS=1`；Agent **0.1.3+** 分项 revision；默认 `REDI_MIN_AGENT_VERSION=0.1.3`，**全部** `/api/nodes/*` 受版本门（请求 `version` 或库内 `agent_version`）；设 `REDI_MIN_AGENT_VERSION=` 可禁用；主控故障时轮询退避至 60s；空证书列表默认连续 3 次才清空节点内存（`REDI_AGENT_EMPTY_CERT_CLEAR_AFTER`） |
| `TRUSTED_PROXIES` / `REDI_ALLOW_TRUST_ALL_PROXIES` | 生产拒绝 `*` 除非 `config('redi.allow_trust_all_proxies')` 为 true（**`php artisan config:cache` 后仍有效**）；Web 与 `queue:work` 启动时均检查 |
| ACME 维护 | CentOS `/etc/cron.d/redi-renew`：**03:10** `redi:renew-certificates`、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`；`redi:normalize-domain-hosts` 可修正历史 IDN / `skip_url` |
| `redi:health` 阈值 | `REDI_HEALTH_QUEUE_PENDING_THRESHOLD`（默认 80）、`REDI_HEALTH_QUEUE_STALE_HOURS`（默认 2）、`REDI_HEALTH_VALIDATING_STUCK_HOURS` / `REDI_RENEW_VALIDATING_STUCK_HOURS`；`--json` 的 `summary` 回显当前配置 |
| 后台运维重定向 | 超管「重试失败部署」等 POST 的 `_redirect` 仅接受站内 `/path`，拒绝 `//` 协议相对外链 |
| 证书表 | `redi_domain_certs.domain_id` 外键级联删除，避免孤儿证书记录 |

## 威胁模型（节点与边缘）

| 场景 | 影响 | 缓解 |
|------|------|------|
| **节点 Token 泄露** | 可拉取本节点分配的证书 PEM、全平台 `redirect-rules`（含 `force_https`）、ACME 挑战 | 立即轮换 Token；隔离主机；`REDI_MIN_AGENT_VERSION`；监控异常心跳 |
| **节点主机被控** | 同上 + 可能读 `env.sh` 中 DB 密码（仅 `redi_node` 存储过程权限） | 最小权限账号；`REDI_DB_TLS`；3306 仅主控 IP；重装节点 |
| **主控 DB 备份泄露** | 证书密文（依赖 `APP_KEY`）、租户数据 | 保护 `APP_KEY` 与备份存储；轮换证书 |
| **Dcat 静态资源在 git** | 前端库版本随仓库发布，需随 Dcat 升级整块替换 | 勿暴露后台到公网无 WAF；计划 Laravel/Dcat 升级 |

## 架构说明（已知限制）

| 项 | 现状 | 建议 |
|----|------|------|
| 节点直连 MySQL | 仍为当前架构，便于部署 | DB 账号最小权限；IP 白名单；`REDI_DB_TLS`；见 [DEPLOY-centos7.md](DEPLOY-centos7.md) §9 |
| `/api.js` 无登录 | 跳转业务需要可被浏览器访问 | 防火墙 + 双档限速（见上；超限返回 `429` / `// rate limit`） |
| `lagran` | 面向被干扰域名，生产默认 **开启**；需 root/CAP_NET_ADMIN | 仅调试时设 `REDI_LAGRAN_DISABLED=1` 或 `lagran.enabled=false` |
| 默认后台密码 | 种子账号仍为文档中的初始密码 | 登录后 middleware 会强制跳转改密（`REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false` 可关） |

## 依赖

发布包 `master.tar.gz`（本地构建或 Release）含 `composer install --no-dev` 的 `vendor`，不含 `.env`。构建/CI 使用 PHP **8.4**；生产建议 PHP 8.0.2+ 并与 lock 一致。`vendor` 与 `*.tar.gz` 不进 git。

CI 会执行 `composer audit`（忽略 abandoned 包）。当前 **Laravel 8** 锁文件在 advisory 库中仍关联若干 CVE/GHSA（见 `composer.json` → `config.audit.ignore`）；清除需 **大版本升级 Laravel/Dcat**，单期以 `APP_DEBUG=false`、后台 HTTPS、不暴露调试页等缓解。升级前勿移除 ignore 项。

打 `v*` 标签发布 Release 时，`.github/workflows/release.yml` 会在上传包之前跑与 CI 相同的 Go/PHPUnit/audit、脚本校验与 CentOS 7 容器冒烟。本地可先执行 `./scripts/dev-preflight.sh`（与 CI 核心步骤一致；无 Docker 时 DB 路径校验会跳过）。
