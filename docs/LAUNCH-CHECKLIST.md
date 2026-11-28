# 上线检查单（一页）

**主推签收单：** 装完后按 §A–E 打勾即可宣告上线准备就绪。安装见 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md)；命令背景见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)。若要逐步核对安装或业务 F 项，见 [ACCEPTANCE.md](ACCEPTANCE.md)（§A / §B）。平台边界见 [CONSOLE.md](CONSOLE.md) §8。

在 Cursor 中可使用可勾选副本：[301 上线检查单](https://cursor.com/canvas/store-0707e8bc-7570-4527-8e26-d32c04eb4d4e/e877d821-4edb-4d1f-9d65-4e1382e20da4)（进度会保存在 Canvas 侧）。

---

## A. 安全与配置（P0）

- [ ] 后台默认密码已修改（仍为种子密码时无法进入业务菜单）
- [ ] `APP_KEY` 已备份；生产 `APP_DEBUG=false`
- [ ] 主控对外 **HTTPS**；节点 `REDI_MASTER_URL` 为 `https://`（非生产实验才用 `REDI_MASTER_INSECURE=1`）
- [ ] `.env`：`REDI_MIN_AGENT_VERSION=0.1.3`（或按策略留空）、ACME 联系邮箱、生产关闭 Staging、`edge_zone` 已配置
- [ ] DNS API 模式：驱动与凭证、Cloudflare Zone ID（如适用）已保存
- [ ] `php artisan config:cache` 已在每次改 `.env` 后执行

## B. 部署与进程

- [ ] **首装**完成：**CentOS 7.x 裸机**（常用 7.9），一条命令见 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md)，未依赖 Docker（首装后换 tar 另见 [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)）
- [ ] 主控：`nginx`、`php-fpm`、`mariadb`、`redi-queue` 均为 active
- [ ] `redi-queue` 含 `--max-time=3600`
- [ ] `/etc/cron.d/redi-renew` 含 renew / prune / health，**无** `schedule:run`，**无** `master/master` 路径
- [ ] Cron 行尾含 `|| redi:ops-webhook --task=…`（或已跑 `patch-master-cron-ops-webhook.sh`）
- [ ] 节点：`redi-multi` active；`setcap cap_net_admin,cap_net_raw`；防火墙仅 22/80/443
- [ ] 主控 `NODE_IPS` 与节点公网 IP 一致；已 `deploy-master` 或 `grant-nodes-from-vars`
- [ ] 安装包是仓库里的 `pkg/master.tar.gz` 与 `pkg/redi-multi.tar.gz`（改过程序后 `dev-preflight` + `build-release-tarballs.sh`，并提交 `pkg/`，见 [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md)）

## C. 运营自检（命令）

- [ ] `cd /opt/301/master && php artisan redi:health` 退出码 **0**（可有 hints，见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)）
- [ ] `php artisan redi:health --json` 已接入监控或人工抽查
- [ ] 可选：`REDI_HEALTH_WEBHOOK_URL` 已配置，`redi:health --notify` 在 Cron 中运行
- [ ] `php artisan redi:renew-certificates --dry-run --days=25` 无异常
- [ ] `curl -fsS "https://<主控>/api/nodes/revision" -H "Authorization: Bearer <token>"` 成功

## D. 业务最小闭环（边缘池）

- [ ] 平台 DNS：pool 多 A + 租户 token→pool（手动或 API 同步）
- [ ] 至少一台启用节点有心跳、公网 IPv4
- [ ] 测试域名 CNAME 预检「已指向」→ HTTP 跳转正常（**200 + JS**，非业务 301）
- [ ] 单域 HTTP-01 证书 → HTTPS 跳转；可选强制 HTTPS（TLS **301**）

## E. 产品边界（排障时勿当缺陷）

- [ ] 团队知晓：不提供 NS 委托、多 pool、逐域 TXT 主流程（见 [CONSOLE.md](CONSOLE.md) §8）
- [ ] 批量 ACME 依赖 **database/redis** 队列，非长期 `sync`
- [ ] DNS API 同步失败按 CONSOLE §2「同步失败后的检查」处理

---

**签字 / 日期：** _______________  

**备注：**
