# 首装后换发布包（不重装 deploy-master）

首次安装见 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md)。本文仅适用于 **主控/节点已在跑**，需要跟上新 `pkg/master.tar.gz` / `pkg/redi-multi.tar.gz` 时。按顺序做，每步可单独回滚。改完程序后 `build-release-tarballs.sh` 会更新 `pkg/`，提交后新装的机器才会用到新包。

## 1. 发布包与代码

在开发机或 CI 产物准备：

```bash
bash scripts/dev-preflight.sh   # 或见 docs/DEVELOPMENT.md 分步
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

跟包后 **`migrate --force` 会应用尚未执行的迁移**。若已导入当前版 `redi_admin.sql` 且 `migrations` 表与发布包一致，此处通常 **无待执行迁移**。后台三语说明见 [I18N.md](I18N.md)。

若「系统外观」里仍是旧版**仅顶层** `system_name` 的 JSON（无 `locales`），换包后可执行一次：

```bash
sudo -u nginx php artisan redi:normalize-platform-branding --dry-run
sudo -u nginx php artisan redi:normalize-platform-branding
```

**含节点程序变更时**：主控 migrate 后，各节点须替换 `redi-multi` 并 `systemctl restart redi-multi`（或重跑 `deploy-node.sh`）。

仅覆盖 tar、未重跑 `deploy-master` 时，请核对 `redi-queue` 的 `queue:work` 是否含 **`--max-time=3600`**（与 [scripts/systemd/redi-queue.service.example](../scripts/systemd/redi-queue.service.example) 一致）。

节点：用 `deploy-node.sh` 或仅替换 `bin/redi-multi` 后 `systemctl restart redi-multi`。

## 2. 主控 `.env` 建议新增/核对

```env
REDI_MIN_AGENT_VERSION=0.1.3
REDI_REQUIRE_DNS_POINTING_TO_ENABLE=true
REDI_DNS_PRECHECK_ALLOW_A_TO_POOL=true
# REDI_HEALTH_WEBHOOK_URL=https://...
# REDI_OPS_WEBHOOK_URL=https://...
```

改完后：`php artisan config:cache`。

## 3. 节点 Agent

- 所有节点替换为发布包内 **Agent 0.1.3+** 的 `redi-multi`（与当前 tar 一致即可）。
- 节点 `env.sh` 可选（见 `node/redi-multi/env.example` 与 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §9）：
  - `REDI_AGENT_EMPTY_CERT_CLEAR_AFTER`
  - `REDI_AGENT_EMPTY_CHALLENGE_CLEAR_AFTER`
  - `REDI_AGENT_EMPTY_RULES_CLEAR_AFTER`
- 新增/下线节点 IP：更新主控 `vars.env` 的 `NODE_IPS` 后重跑 `deploy-master.sh` 或 `grant-nodes-from-vars.sh`。

## 4. Cron：续期 / 清理 / 健康检查 + 失败 Webhook

新装 `deploy-master` 已写入带 `|| redi:ops-webhook` 的 cron。**旧机房**若路径仍是 `master/master` 或仍有 `schedule:run`，执行：

```bash
sudo bash scripts/patch-master-cron-ops-webhook.sh
```

验证见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 与 [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)。

## 5. 运营验收

见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)。

## 6. 回滚要点

- 主控：恢复上一版 tar + `migrate:rollback`（仅当确认迁移可逆）。
- `.env`：恢复旧 `REDI_MIN_AGENT_VERSION` 需同步旧节点二进制。
- Cron：勿恢复 `php artisan schedule:run`。
