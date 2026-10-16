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
php artisan redi:health --json | jq '.hints,.summary.queue_connection,.summary.cache_driver'
```

`hints` 中不应再出现 `queue_cache_file`（在已改 redis 且队列非 sync 时）。

## systemd

继续复用 `scripts/systemd/redi-queue.service.example`；多实例可复制 unit 并区分 `WorkingDirectory` 与日志，或使用 Supervisor。
