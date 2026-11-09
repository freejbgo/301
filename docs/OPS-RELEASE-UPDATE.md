# 发布包更替（首装之后）

本产品按 **绿场首装** 交付：`db/redi_admin.sql` 一次导入 + CentOS `deploy-*.sh`（见 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md)）。**不存在**从旧版手工迁移的历史环境；日后仅在机房内 **更换主控/节点 tar** 时使用本文。

## 1. 准备新包

在 **发版机** 执行：

```bash
bash scripts/dev-preflight.sh
bash scripts/build-release-tarballs.sh
```

将 `master.tar.gz`、`redi-multi.tar.gz` 传到机房，更新 `vars.env` 中的 `REDI_MASTER_TAR` / `REDI_NODE_TAR`，然后：

- 主控：`sudo bash scripts/centos7/deploy-master.sh`（或按下面「仅换二进制」）
- 各节点：`sudo bash scripts/centos7/deploy-node.sh`

`deploy-master` 会执行 `migrate --force`。若本次发布 **已用当前版 `redi_admin.sql` 绿场安装** 且 `migrations` 表与包一致，通常 **无待执行迁移**；有新迁移时脚本会自动应用。

## 2. 仅覆盖主控 tar（不停机整脚本时）

程序目录 **`/opt/301/master`**（`artisan` 在此；勿写成 `/opt/301/master/master`）：

```bash
sudo systemctl stop redi-queue nginx php*-fpm 2>/dev/null || true
sudo cp -a /opt/301/master/.env "/root/redi-master.env.bak.$(date +%Y%m%d%H%M)"
cd /opt/301/master && sudo tar -xzf /path/to/master.tar.gz --strip-components=1
cd /opt/301/master
sudo -u nginx php artisan migrate --force
sudo -u nginx php artisan config:cache
sudo systemctl start php*-fpm nginx redi-queue
```

发布包已含 `vendor` 时不必再 `composer install`。

## 3. 节点

替换 `redi-multi` 后 `systemctl restart redi-multi`，或重跑 `deploy-node.sh`。主控与节点版本应同步更替。

**Agent 可选 env**（默认一般无需改）：`REDI_AGENT_EMPTY_CERT_CLEAR_AFTER`、`REDI_AGENT_EMPTY_CHALLENGE_CLEAR_AFTER`、`REDI_AGENT_EMPTY_RULES_CLEAR_AFTER`，见 [node/redi-multi/env.example](../node/redi-multi/env.example) 与 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §9。

**新增/下线节点 IP**：更新 `vars.env` 的 `NODE_IPS` 后 `sudo bash scripts/centos7/grant-nodes-from-vars.sh` 或重跑 `deploy-master.sh`。

## 4. 主控 `.env` 建议核对

```env
REDI_MIN_AGENT_VERSION=0.1.3
REDI_REQUIRE_DNS_POINTING_TO_ENABLE=true
REDI_DNS_PRECHECK_ALLOW_A_TO_POOL=true
# REDI_HEALTH_WEBHOOK_URL=https://...
# REDI_OPS_WEBHOOK_URL=https://...
```

改后：`php artisan config:cache`。

## 5. Cron 与 Webhook

**新装** `deploy-master` 已写入 `/etc/cron.d/redi-renew`（**03:10** 续期、**04:10** 清理 ACME、**08:30** `redi:health --notify`），且含 `|| redi:ops-webhook`。**勿**再配置 `php artisan schedule:run`。

核对：

```bash
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
cd /opt/301/master && sudo -u nginx php artisan redi:health
```

若 cron 被手工改坏，可 `sudo bash scripts/patch-master-cron-ops-webhook.sh`。

`REDI_HEALTH_WEBHOOK_URL`：配置且 `--notify` 成功发出时 **退出 0**（避免与 `|| redi:ops-webhook` 重复）；未配置 URL 且有 issues 时退出 1，由 ops webhook 兜底。

## 6. 验收与回滚

验收：[PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)、[LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)。

回滚：恢复上一版 tar；仅在确认迁移可逆时对主控执行 `migrate:rollback`；保留上一版 tar 路径见 [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md)。
