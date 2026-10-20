# 生产就绪检查（上线运营 / 全功能）

本文档汇总 **阻塞上线** 与 **验收必做** 项，与 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 及 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) 配合使用。**生产环境仅维护 CentOS 7.9（7.x）部署路径。**

- **已上线升级（不重装）**：[UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)（含 `scripts/patch-master-cron-ops-webhook.sh`）
- **P0/P1/P2 全表**：[ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md)（合并基线见 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §7）

## 阻塞上线（P0，本仓库已加固）

| 领域 | 要求 |
|------|------|
| 域名占坑 | 默认 **新建为停用**；租户启用前须 CNAME 到 edge 或 **A 到启用节点公网 IP**（`REDI_REQUIRE_DNS_POINTING_TO_ENABLE`，平台管理员可跳过） |
| 后台安全 | 默认密码拦截不再放行 `dcat-api`；`dcat-api` 须已登录后台；登录 10 次/分钟限速；域名列表分组名 HTML 转义 |
| 证书下发 | 节点 register / 重新启用时 `syncDeploymentsForNode` 补齐 `redi_cert_deployments`；主控空证书列表时节点清空内存证书 |
| 节点 Agent | 心跳优先于拉取；拉取失败不阻断后续心跳；ack 403/409 不触发重新 register；Agent ≥ `0.1.3`（`REDI_MIN_AGENT_VERSION`） |
| API 限流 | 节点 API 在认证后按 `node_id` 限流（`throttle:redi-node`） |
| ACME / 生产配置 | `redi:health` 在生产下检查：`app_debug_enabled`、`acme_email_missing`、`acme_staging_enabled`、`edge_zone_missing`、`dns_driver_invalid`、`dns_api_credentials_missing`、`dns_zone_id_missing` |
| 节点池 | `redi:health` 告警 `node_pool_empty` / `node_no_public_ip`；pool A 仅使用合法 IPv4（节点模型 + DNS reconcile 双层过滤） |
| 证书续期/下发 | `redi:renew-certificates` 与 `syncDeploymentsForNode` 跳过非法主机名的证书记录，避免无效 ACME 入队 |

## 部署前

1. 修改后台默认密码（种子文档密码在 `config/redi.php` 的 `known_default_admin_passwords` 中会被拦截）。
2. 确认 `APP_KEY` 稳定且已备份（证书密文依赖它）。
3. 主控仅 HTTPS 暴露；节点 `REDI_MASTER_URL` 使用 `https://`（实验环境才用 `REDI_MASTER_INSECURE=1`）。
4. 按 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 执行主控/节点脚本，并准备 `release` 包（见 `scripts/ensure-release-tarballs.sh`）。主控 `NODE_IPS` 变更后须 `deploy-master.sh` 或 `grant-nodes-from-vars.sh` 同步 MySQL/firewalld。
5. **在开发机打 tar 或发 `v*` tag 前**：`bash scripts/dev-preflight.sh`（见 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §9）。

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
# issues/hints 各 code 含义见 AUDIT-CENTOS7-PRODUCTION.md §3

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

`redi:health` 的 `issues` / `hints` 各 `code` 含义见 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §3。摘要：

- 生产配置：`APP_DEBUG`、ACME 邮箱 / Staging、`edge_zone`（见 §3 表）
- Cron **08:30** `redi:health --notify`：建议配置 `REDI_HEALTH_WEBHOOK_URL`（未配置且有 issues 时退出 1，由 `|| redi:ops-webhook` 兜底）
- `failed_jobs` > 0 → 严重（`queue_failed_jobs`）
- `jobs` 积压 ≥ `REDI_HEALTH_QUEUE_PENDING_THRESHOLD`（默认 80）→ `queue_backlog`；`available_at` 超过 `REDI_HEALTH_QUEUE_STALE_HOURS`（默认 2）未执行 → `queue_stale`（仅 `QUEUE_CONNECTION=database`）

完整路线图见 [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md)。多 worker 队列见 [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md)；节点 DB TLS 见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §9。

## 回滚

- 主控：恢复上一版 `release` 包与 `php artisan migrate:rollback`（仅在确认无不可逆迁移时使用）。
- 节点：替换 `redi-multi` 二进制并 `systemctl restart redi-multi`（或部署脚本注册的服务名）。
