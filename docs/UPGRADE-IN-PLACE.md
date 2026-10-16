# 已上线环境升级（不重装 deploy-master）

适用于 **主控/节点已在跑**，只需跟上当前 `main` 发布能力时使用。按顺序做，每步可单独回滚。

## 1. 发布包与代码

在开发机或 CI 产物准备（发版前建议先跑 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §9）：

```bash
./scripts/build-master-tar.sh
./scripts/build-node-tar.sh
```

主控（示例路径 `/opt/301/master`）：

```bash
sudo systemctl stop redi-queue nginx php*-fpm 2>/dev/null || true
cd /opt/301/master && sudo tar -xzf /path/to/master.tar.gz --strip-components=1
cd /opt/301/master/master && sudo -u nginx composer install --no-dev -o
sudo -u nginx php artisan migrate --force
sudo -u nginx php artisan config:cache
sudo systemctl start php*-fpm nginx redi-queue
```

节点：用 `deploy-node.sh` 或仅替换 `bin/redi-multi` 后 `systemctl restart redi-multi`。

## 2. 主控 `.env` 建议新增/核对

```env
REDI_MIN_AGENT_VERSION=0.1.3
REDI_REQUIRE_DNS_POINTING_TO_ENABLE=true
REDI_DNS_PRECHECK_ALLOW_A_TO_POOL=true
# 可选告警
# REDI_HEALTH_WEBHOOK_URL=https://...
# REDI_OPS_WEBHOOK_URL=https://...
```

改完后：`php artisan config:cache`。

## 3. 节点 Agent

- 所有节点升级到含 **Agent 0.1.3** 的 `redi-multi`。
- 过渡：可暂时 `REDI_MIN_AGENT_VERSION=` 清空，升级完节点再改回 `0.1.3`。

## 4. Cron：续期 / 清理 / 健康检查 + 失败 Webhook

新装脚本已写入 `bash -c '... || php artisan redi:ops-webhook ...'`。**已有机房**可执行仓库脚本（需 root）：

```bash
sudo bash scripts/patch-master-cron-ops-webhook.sh /opt/301/master/master
```

或手工编辑 `/etc/cron.d/redi-renew`（Cron 用户与 deploy 一致，CentOS 一般为 `nginx`）：

```cron
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
10 3 * * * nginx bash -c 'cd /opt/301/master/master && /usr/bin/php artisan redi:renew-certificates >> /opt/301/master/master/storage/logs/renew.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:renew-certificates >> /opt/301/master/master/storage/logs/renew.log 2>&1'
10 4 * * * nginx bash -c 'cd /opt/301/master/master && /usr/bin/php artisan redi:prune-acme-challenges >> /opt/301/master/master/storage/logs/prune-acme.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:prune-acme-challenges >> /opt/301/master/master/storage/logs/prune-acme.log 2>&1'
30 8 * * * nginx bash -c 'cd /opt/301/master/master && /usr/bin/php artisan redi:health --notify >> /opt/301/master/master/storage/logs/health.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:health >> /opt/301/master/master/storage/logs/health.log 2>&1'
```

验证：

```bash
php artisan redi:health
php artisan redi:health --json   # 监控用；issues/hints code 见 AUDIT §3
php artisan list | grep redi:
```

## 5. 运营验收

见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 与 [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md)。

## 6. 回滚要点

- 主控：恢复上一版 tar + `migrate:rollback`（仅当确认迁移可逆）。
- `.env`：恢复旧 `REDI_MIN_AGENT_VERSION` 需同步旧节点二进制。
- Cron：保留旧三行亦可（仅失去失败 Webhook，不影响续期本身）。
