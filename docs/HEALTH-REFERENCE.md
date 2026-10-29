# `redi:health` 问题码与配置建议

主控命令：`php artisan redi:health` / `--json` / `--notify`（Cron **08:30** 使用 `--notify`）。

- **`issues[]`**：影响命令退出码（非空时一般为 **1**，适合告警）。
- **`hints[]`**：仅建议，不影响退出码。

采集示例：

```bash
php artisan redi:health --json | jq '.summary.health_queue_pending_threshold,.summary.health_queue_stale_hours,.summary.health_validating_stuck_hours,.summary.health_renew_validating_stuck_hours,.issues,.hints'
```

Cron、Webhook 与验收见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)、[OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md)。

## `issues[]`（影响退出码）

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

## `hints[]`（不影响退出码）

| `code` | 含义 |
|--------|------|
| `queue_cache_file` | 队列非 sync 但 `CACHE_DRIVER=file` |
| `queue_database_single_host` | 队列用 database 且与 DB 同机（多 worker 建议 Redis） |
| `agent_version_gate_off` | 生产下 `REDI_MIN_AGENT_VERSION` 为空 |
| `dns_api_tenants_unsynced` | DNS API 模式下仍有租户 edge 未 `dns_synced_at`（可能需重试同步；见 [CONSOLE.md](CONSOLE.md) §2） |

## 运维 Webhook（`REDI_HEALTH_WEBHOOK_URL` / `REDI_OPS_WEBHOOK_URL`）

`redi:health --notify` 与 `redi:ops-webhook` 在 POST 前会经 `WebhookUrlGuard` 校验目标 URL：

- 仅允许 `http` / `https`；拒绝字面私网/保留 IP、`localhost`、`.local` / `.internal` 等主机名。
- 对域名会做 DNS 解析（A/AAAA）；**解析失败**、**无记录**，或 **任一记录指向私网/保留地址** 时 **不发送** POST（fail-closed，防 SSRF）。
- 因此 Cron 里 `|| redi:ops-webhook` 可能因 URL 不合法而静默跳过；排障时检查 `.env` 中的 Webhook URL 是否为公网可解析主机，并见 [SECURITY.md](../SECURITY.md) §凭证与出站。
