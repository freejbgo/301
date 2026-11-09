# 生产就绪检查（上线运营 / 全功能）

本文档汇总 **阻塞上线** 与 **验收必做** 项，与 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 配合使用。**生产环境仅维护 CentOS 7.9（7.x）部署路径。**

- **已上线升级（不重装）**：[OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)（含 `scripts/patch-master-cron-ops-webhook.sh`）
- **一页打勾清单**：[LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)（[Canvas 可勾选副本](https://cursor.com/canvas/store-0707e8bc-7570-4527-8e26-d32c04eb4d4e/e877d821-4edb-4d1f-9d65-4e1382e20da4)）

## 阻塞上线（P0 摘要）

| 领域 | 要求 |
|------|------|
| 域名占坑 | 默认 **新建为停用**；启用前 DNS（CNAME 或 A 到池）；`REDI_REQUIRE_DNS_POINTING_TO_ENABLE` |
| 后台安全 | 默认密码拦截；`dcat-api` 须登录；登录限速；列表 XSS 转义 |
| 证书与 Agent | 部署行补齐；主控连续空列表默认 **3** 次拉取后才清空节点内存（证书 / ACME 挑战 / redirect-rules，见 `REDI_AGENT_EMPTY_*_CLEAR_AFTER`）；Agent ≥ `0.1.3` |
| 生产配置 | `redi:health` 检查 debug、ACME、edge_zone、DNS 驱动与凭证等（见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)） |
| 节点池 | 启用节点公网 IP；pool A 仅合法 IPv4 |

逐项勾选见 **[LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)** §A–E。

## 发布前门禁（仓库内）

合并或打标签前在仓库根目录执行（与 CI / Release 工作流核心步骤一致；无 Docker 时会跳过部分 DB 容器校验）：

```bash
./scripts/dev-preflight.sh
```

## 验收命令（真机）

```bash
# 主控服务（CentOS deploy-master）
systemctl is-active nginx php-fpm mariadb redi-queue
grep -q '\-\-max-time=3600' /etc/systemd/system/redi-queue.service

# Cron（与 deploy-master / patch-master-cron-ops-webhook 一致）
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
# Cron 输出日志（排障续期/清理/health）：storage/logs/renew.log、prune-acme.log、health.log

# 运营自检（节点心跳、证书到期；可接监控，非 0 表示有待处理项）
cd /opt/301/master && php artisan redi:health
php artisan redi:health --json
# JSON 含 summary（queue/cache 驱动、health/renew validating 与队列阈值）、hints（多 worker 时请 redis 缓存）、issues（非空则退出码 1）
# issues/hints 各 code 含义见 HEALTH-REFERENCE.md
# DNS API 同步曾失败或 edge 未 dns_synced_at：hints 可出现 dns_api_tenants_unsynced；运维步骤见 docs/CONSOLE.md「同步失败后的检查」

# 有问题时通知 Webhook（.env 配置 REDI_HEALTH_WEBHOOK_URL，POST JSON，含 text 摘要）
# --notify：webhook 发送成功（或无 issues）时退出 0；未配置 URL 且有 issues 时退出 1，Cron 行 || ops-webhook 兜底
php artisan redi:health --notify

# Cron 续期/清理在 artisan 异常退出时走 || redi:ops-webhook
# php artisan redi:ops-webhook --task=redi:renew-certificates --message="测试告警"

# 续期预演（cron 实际跑的是 redi:renew-certificates，无 --dry-run）
php artisan redi:renew-certificates --dry-run --days=25

# 主控
curl -fsS "https://<master>/api/nodes/revision" -H "Authorization: Bearer <token>"

# 节点健康（部署脚本内已有）
curl -fsS "http://127.0.0.1:<node-port>/revision"

# DB 部署路径（CI 同款；在仓库根目录执行）
bash scripts/verify-redi-db-deploy-path.sh
```

## DNS API 同步（运营）

- 使用 **API 自动** 解析时，同步失败请按 [CONSOLE.md](CONSOLE.md) §2 **「同步失败后的检查」** 核对厂商侧是否已部分写入，修正后再点同步；`dns_synced_at` 仅在全步成功后才前进。
- 监控/巡检：`php artisan redi:health --json` 的 `hints` 中 `dns_api_tenants_unsynced` 表示仍有租户 edge 未记录成功同步（非阻塞退出码）。

## 后台操作（运营）

- **节点管理** →「重试失败证书」：仅超级管理员，将该节点 `failed` 部署行改回 `pending`。
- **域名管理** →「重新下发证书」：向全部启用节点重新 `queueDeploy`（租户仅自己的域名）。
- **域名列表筛选「证书」**：待处理 / 已生效 / 14 天内到期 / 未申请。
- **批量操作** →「批量重新下发证书」：对已选中且可解密的证书重新部署。
- **导出 CSV**：域名列表工具栏（沿用当前筛选条件）；租户仅导出自己名下域名（`BelongsToAdminTenant` 作用域）；导出时对 `=+/@-` 开头字段加 `'` 防公式注入。
- **重试全部失败部署**：超管首页 / 节点管理 / `php artisan redi:requeue-failed-deployments`（可选 `--node=`、`--cert=`）。

## 全功能场景

1. **多租户域名**：不同 `admin_id` 域名互不可见；CNAME 预检与跳转规则下发。
2. **多节点**：启用第二节点后，已有 `active` 证书应出现 pending 部署并在节点拉取后 ack。
3. **HTTPS**：签发 → deploying → 各节点 ack → `active`；停用域名后证书不再出现在节点列表。
4. **强制 HTTPS**：仅影响 TLS 层 301；正常跳转仍为 200 + JS（产品行为）。

## P1 运营（health 已含）

`redi:health` 的 `issues` / `hints` 各 `code` 含义见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)。摘要：

- 生产配置：`APP_DEBUG`、ACME 邮箱 / Staging、`edge_zone`（见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)）
- Cron **08:30** `redi:health --notify`：建议配置 `REDI_HEALTH_WEBHOOK_URL`（未配置且有 issues 时退出 1，由 `|| redi:ops-webhook` 兜底）。Webhook 目标须公网可解析且 DNS 仅指向公网 IP，否则 **不会 POST**（见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md) §运维 Webhook、[SECURITY.md](../SECURITY.md)）
- `failed_jobs` > 0 → 严重（`queue_failed_jobs`）
- `jobs` 积压 ≥ `REDI_HEALTH_QUEUE_PENDING_THRESHOLD`（默认 80）→ `queue_backlog`；`available_at` 超过 `REDI_HEALTH_QUEUE_STALE_HOURS`（默认 2）未执行 → `queue_stale`（仅 `QUEUE_CONNECTION=database`）

多 worker 见 [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md)；节点 DB TLS 见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §9。

## 已知限制与计划增强

不阻塞首单上线，排障时勿当缺陷：

| 项 | 说明 |
|----|------|
| Laravel / PHP 大版本 | 当前锁 PHP 8.0 与 Dcat `2.2.3-beta` |
| 租户隔离 | 持续收紧 API/后台全表策略 |
| 运行环境 | CentOS 7 已 EOL，长期使用 vault 源；换 OS 属单独项目 |
| 跳转与 HTTPS | 正常跳转 **200 + JS**；仅 **force HTTPS** 为 TLS **301** |
| 域名占坑 | 平台内 `original_url` 唯一；新建默认停用，启用前 DNS 预检 |

产品边界与后台操作见 [CONSOLE.md](CONSOLE.md) §8。

## 回滚

- 主控：恢复上一版 `release` 包与 `php artisan migrate:rollback`（仅在确认无不可逆迁移时使用）。
- 节点：替换 `redi-multi` 二进制并 `systemctl restart redi-multi`（或部署脚本注册的服务名）。
