# 多 worker / 多机队列（主控）

## 何时需要

- 证书签发（ACME）任务积压，`jobs` 表长期超过 `REDI_HEALTH_QUEUE_PENDING_THRESHOLD`
- 计划在 **多台主控** 或 **同一台多个** `queue:work` 进程

`IssueDomainCertificateJob` 使用 `WithoutOverlapping`，锁依赖 **共享缓存**。`CACHE_DRIVER=file` 仅对 **单机单 worker** 可靠。

## 推荐配置（主控 `.env`）

```env
QUEUE_CONNECTION=database
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

CentOS 7 安装 Redis 示例（主控本机）：

```bash
yum install -y redis
systemctl enable --now redis
```

修改 `.env` 后：

```bash
sudo -u nginx php artisan config:cache
sudo systemctl restart redi-queue
```

## 自检

```bash
php artisan redi:health --json | jq '.summary.health_queue_pending_threshold,.summary.health_queue_stale_hours,.summary.health_validating_stuck_hours,.summary.health_renew_validating_stuck_hours,.hints,.summary.queue_connection,.summary.cache_driver'
```

`hints` 中不应再出现 `queue_cache_file`（在已改 redis 且队列非 sync 时）。`queue_stale`（`jobs.available_at` 超过 `REDI_HEALTH_QUEUE_STALE_HOURS`，默认 2）仅在 `QUEUE_CONNECTION=database` 时检测；`--json` 的 `summary` 含 `health_queue_pending_threshold` / `health_queue_stale_hours` / `health_validating_stuck_hours` / `health_renew_validating_stuck_hours`。各 hint/issue `code` 见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)。

## systemd

继续复用 `scripts/systemd/redi-queue.service.example`（含 `--max-time=3600`，与 `deploy-master` 一致）；多实例可复制 unit 并区分 `WorkingDirectory` 与日志，或使用 Supervisor。
