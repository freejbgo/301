# 边缘池业务验收（F1–F8）

在测试/预发环境按序执行；架构背景见 [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)。

## 环境前提

绿场库见 [GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md)（**一次导入** `redi_admin.sql`，含 `redi_node_bump_skip`）。

- 主控：**CentOS 发布包**（`deploy-master.sh` 解压的 `/opt/301/master`，**已含 `vendor`**，机房不需 `composer install`）；`QUEUE_CONNECTION=database`；`redi-queue` systemd 单元或等效 `queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600`。开发机从源码调试时才在 `master/master` 跑 `composer install` 与 `migrate --force`。
- 续期/清理/健康：CentOS 用 `deploy-master` 写入的 `/etc/cron.d/redi-renew`（**03:10** 续期、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`）；各行失败应带 `|| php artisan redi:ops-webhook --task=…` 兜底。配置 `REDI_HEALTH_WEBHOOK_URL` 后 Cron 在有 issues 且 webhook 发送成功时退出 0。手工部署可写同等 cron，**勿**配置 `php artisan schedule:run`（仅认 `/etc/cron.d/redi-renew`）。多台 `queue:work` 时 `CACHE_DRIVER=redis`；`queue:work` 须含 `--max-time=3600`。
- 节点：`REDI_MASTER_URL`、`REDI_NODE_TOKEN`、`REDI_DB_*`；防火墙对公网开放 80/443（CentOS 7 脚本不开放 8080）。`/c.js`、`/api.js` 由 **80/443** 同源提供（带 IP 限速），见 [SECURITY.md](../SECURITY.md)。

## F1 手动 DNS

1. 后台配置平台主域名，解析模式选 **手动**（无「同步」按钮，仅参考表）。
2. 添加 ≥2 节点 IP。
3. 按后台 **Pool A** 与 **租户 token→pool CNAME** 两张参考表，在 DNS 服务商控制台自行添加：`pool.平台域` 多条 **A** → 节点 IP；各租户 `token.平台域` **CNAME** → `pool.平台域`。
4. 租户客户域 CNAME 预检为「已指向」。

## F2 API 同步 pool

1. DNS 驱动选 Cloudflare（或已配置的阿里云/DNSPod/dns.com）。
2. 填写凭证，解析模式选 **API 自动**，点击「同步 Pool / 租户 CNAME 到 DNS 厂商」。
3. 在厂商面板确认 pool A 与 token CNAME 与节点列表一致。
4. 若租户 `edge_fqdn` 与当前平台主域名不一致，同步应 **失败** 且 `dns_synced_at` 不前进（全部不一致时不应写入厂商；见 [CONSOLE.md](CONSOLE.md)）。
5. **无启用节点公网 IPv4** 时同步应 **失败**（提示含「没有启用的节点公网 IPv4」，不调用厂商 API）；开启 `REDI_PUBLISH_POOL_AAAA` 时须先修正节点 IPv6（见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §12）。
6. （**可选复盘**）若同步曾失败或 `dns_synced_at` 未前进：按 [CONSOLE.md](CONSOLE.md) §2 **「同步失败后的检查」** 核对厂商侧记录并重试；`php artisan redi:health --json` 可出现 hint `dns_api_tenants_unsynced`（不单独作为 F2 失败项，退出码仍可为 0）。

## F3 HTTP 跳转

1. 客户域 CNAME 生效后，访问 `http://客户域/`。
2. 跳转逻辑正常。页面脚本来自客户域名上的 `/api.js`，不要求开放 8080。

## F4 单域 HTTP-01 → HTTPS

1. 单域「申请证书」，观察队列与证书状态至「已生效」。
2. `https://客户域/` 证书有效，跳转正常。

## F5 批量证书

1. 勾选 ≥10 个域名「批量申请证书」。
2. 队列逐步完成，无大面积失败（注意 LE 限流）。

## F6 强制 HTTPS

1. 域名开启「强制 HTTPS」。
2. `http://客户域/` 返回 301 到 `https://`。HTTPS 页面脚本为同源 `/c.js`、`/api.js`（不访问 `https://节点IP:8080`）。

## F7 续期

1. 将测试证书记录 `expires_at` 改为 25 天内（或 staging 短证）。
2. 执行 `php artisan redi:renew-certificates`，确认自动入队续签且客户无需改 DNS。

## F8 新节点入网

1. 新增节点 IP，同步 pool（手动或 API）。
2. 新节点配置 Token 后，确认拉取**全量**证书且 ACK 成功；旧节点仍正常。

---

## 运维交叉引用

| 主题 | 文档 |
|------|------|
| DNS API 同步失败 | [CONSOLE.md](CONSOLE.md) §2「同步失败后的检查」 |
| 真机 CentOS 安装与 Cron | [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) |
| 上线命令与 health | [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) |
| 节点 Agent 退避 / 空证书防抖 | [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) §3、`node/README.md` |
