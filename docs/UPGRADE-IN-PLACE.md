# 已上线环境升级（不重装 deploy-master）

适用于 **主控/节点已在跑**，只需跟上当前 `main` 发布能力时使用。按顺序做，每步可单独回滚。

## 1. 发布包与代码

在开发机或 CI 产物准备：

```bash
bash scripts/dev-preflight.sh   # 或见 AUDIT-CENTOS7-PRODUCTION.md §9 分步
./scripts/build-master-tar.sh
./scripts/build-node-tar.sh
```

主控（发布包程序目录 **`/opt/301/master`**，`artisan` 在此；勿写成 `/opt/301/master/master`）：

```bash
sudo systemctl stop redi-queue nginx php*-fpm 2>/dev/null || true
sudo cp -a /opt/301/master/.env "/root/redi-master.env.bak.$(date +%Y%m%d%H%M)"
# 覆盖解压（保留上一步备份的 .env；包内不含 .env）
cd /opt/301/master && sudo tar -xzf /path/to/master.tar.gz --strip-components=1
# 与 deploy-master 等价写法：sudo tar xzf /path/to/master.tar.gz -C /opt/301
cd /opt/301/master
# 发布包已含 vendor 时可跳过 composer；缺依赖时再执行：
sudo -u nginx composer install --no-dev -o
sudo -u nginx php artisan migrate --force
sudo -u nginx php artisan config:cache
sudo systemctl start php*-fpm nginx redi-queue
```

仅覆盖 tar、未重跑 `deploy-master` 时，请核对 `/etc/systemd/system/redi-queue.service` 的 `queue:work` 是否含 **`--max-time=3600`**（与 [scripts/systemd/redi-queue.service.example](../scripts/systemd/redi-queue.service.example) 一致）；过旧可手工改 unit 后 `systemctl daemon-reload && systemctl restart redi-queue`，或重跑 `deploy-master` 刷新 unit。

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
- 新增/下线节点 IP：更新主控 `vars.env` 的 `NODE_IPS` 后重跑 `deploy-master.sh`，或仅同步授权/防火墙时 `sudo bash scripts/centos7/grant-nodes-from-vars.sh`（读 `vars.env` 的 `MYSQL_ROOT_PASS` / `DB_PASS`；从列表删 IP 或清空 `NODE_IPS` 后重跑可撤销已记录授权）。单 IP 见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §5 手工 `grant-node-mysql` / `revoke-node-mysql` 与 firewalld 脚本。

## 4. Cron：续期 / 清理 / 健康检查 + 失败 Webhook

新装 `deploy-master` 已写入 `bash -c 'cd /opt/301/master && ... || php artisan redi:ops-webhook ...'`。**已有机房**若 Cron 里仍是错误的 `master/master` 路径，或 root/旧 crontab 里仍有 `php artisan schedule:run`，请删掉 `schedule:run`（续期/清理/health 仅认 `/etc/cron.d/redi-renew`），再执行仓库脚本（需 root）：

```bash
sudo bash scripts/patch-master-cron-ops-webhook.sh
# 自定义程序目录时传入 artisan 所在路径，例如：
# sudo bash scripts/patch-master-cron-ops-webhook.sh /opt/301/master
```

或手工编辑 `/etc/cron.d/redi-renew`（Cron 用户与 deploy 一致，CentOS 一般为 `nginx`）：

```cron
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
10 3 * * * nginx bash -c 'cd /opt/301/master && /usr/bin/php artisan redi:renew-certificates >> /opt/301/master/storage/logs/renew.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:renew-certificates >> /opt/301/master/storage/logs/renew.log 2>&1'
10 4 * * * nginx bash -c 'cd /opt/301/master && /usr/bin/php artisan redi:prune-acme-challenges >> /opt/301/master/storage/logs/prune-acme.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:prune-acme-challenges >> /opt/301/master/storage/logs/prune-acme.log 2>&1'
30 8 * * * nginx bash -c 'cd /opt/301/master && /usr/bin/php artisan redi:health --notify >> /opt/301/master/storage/logs/health.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:health >> /opt/301/master/storage/logs/health.log 2>&1'
```

验证：

```bash
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
cd /opt/301/master && sudo -u nginx php artisan redi:health
cd /opt/301/master && sudo -u nginx php artisan redi:health --json   # 监控用；issues/hints code 见 AUDIT §3
cd /opt/301/master && sudo -u nginx php artisan list | grep redi:
```

`08:30` 的 `redi:health --notify`：在配置 `REDI_HEALTH_WEBHOOK_URL` 且能发出通知时，即使有 issues 也 **退出 0**（避免与 health webhook 重复触发 `|| redi:ops-webhook`）；未配置 URL 且有 issues 时退出 1，由 `|| redi:ops-webhook` 兜底。手工巡检仍用无 `--notify` 的 `redi:health` / `--json`（有 issues 时退出 1）。

## 5. 运营验收

见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 与 [ROADMAP-PRODUCTION.md](ROADMAP-PRODUCTION.md)。

## 6. 回滚要点

- 主控：恢复上一版 tar + `migrate:rollback`（仅当确认迁移可逆）。
- `.env`：恢复旧 `REDI_MIN_AGENT_VERSION` 需同步旧节点二进制。
- Cron：保留旧 cron 亦可（仅失去失败 Webhook 或缺 prune/health 行时须手工补齐）；勿恢复 `schedule:run`。
