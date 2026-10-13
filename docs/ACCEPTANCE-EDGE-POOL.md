# 一期验收清单（F1–F8）

在测试/预发环境按序执行，通过后在 `DEVELOPMENT-EDGE-POOL-HTTPS.md` §八 勾选 F 项。

## 环境前提

- 主控：`composer install`、`migrate`、`QUEUE_CONNECTION=database`、`php artisan queue:work` 常驻
- 续期 crontab：`10 3 * * * cd .../master/master && php artisan redi:renew-certificates`
- 节点：`REDI_MASTER_URL`、`REDI_NODE_TOKEN`、`REDI_DB_*`；防火墙对公网开放 80/443（CentOS 7 脚本不开放 8080；`/api.js` 由 80/443 路径或内网访问，见 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md)）

## F1 手动 DNS

1. 后台配置平台主域名，解析模式选 **手动**（无「同步」按钮，仅参考表）。
2. 添加 ≥2 节点 IP。
3. 按后台 **Pool A** 与 **租户 token→pool CNAME** 两张参考表，在 DNS 服务商控制台自行添加：`pool.平台域` 多条 **A** → 节点 IP；各租户 `token.平台域` **CNAME** → `pool.平台域`。
4. 租户客户域 CNAME 预检为「已指向」。

## F2 API 同步 pool

1. DNS 驱动选 Cloudflare（或已配置的阿里云/DNSPod/dns.com）。
2. 填写凭证，解析模式选 **API 自动**，点击「同步 Pool / 租户 CNAME 到 DNS 厂商」。
3. 在厂商面板确认 pool A 与 token CNAME 与节点列表一致。

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
