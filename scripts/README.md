# 运维与发版脚本索引

| 脚本 | 用途 |
|------|------|
| [dev-preflight.sh](dev-preflight.sh) | 发版前自检（Go + PHPUnit + 脚本语法 + verify），见 [docs/DEVELOPMENT.md](../docs/DEVELOPMENT.md) |
| [build-release-tarballs.sh](build-release-tarballs.sh) | **推荐**：一次打出主控 + 节点 tar（见 [RELEASE-PACKAGES.md](../docs/RELEASE-PACKAGES.md)） |
| [build-master-tar.sh](build-master-tar.sh) / [build-node-tar.sh](build-node-tar.sh) | 单独打主控/节点发布包 |
| [ensure-release-tarballs.sh](ensure-release-tarballs.sh) | `deploy-*.sh` 共用：缺 tar 时尝试本地构建 |
| [verify-redi-db-deploy-path.sh](verify-redi-db-deploy-path.sh) | Docker 临时 MariaDB：建库 collation + `redi_admin.sql` + `CALL redi_node_bump_skip` + `locale` / `last_error_code` 列冒烟（CI/`VERIFY_REDI_REQUIRE_DOCKER=1` 时无 docker 会失败） |
| [verify-redi-node-procedure-sync.php](verify-redi-node-procedure-sync.php) | SQL dump 与迁移 `100009` 存储过程一致 |
| [verify-master-cron-templates.sh](verify-master-cron-templates.sh) | 静态校验 `deploy-master` / `patch-master-cron` 的 Cron 模板（无 `schedule:run`、无 `master/master` 路径、三条任务 + `ops-webhook` 兜底且 `--task=` 与 artisan 命令一致、`--max-time=3600`） |
| [verify-node-deploy-templates.sh](verify-node-deploy-templates.sh) | 静态校验 `deploy-node`、`vars.example.env`、`env.example` 与 lagran 脚本（HTTPS Agent、静态 ldd、`redi_node`、setcap、lagran_ports.py、`REDI_DB_PASSWORD`） |
| [verify-grant-nodes-templates.sh](verify-grant-nodes-templates.sh) | 静态校验 `grant-nodes-from-vars` 与 `deploy-master` 共用 `redi_sync_node_ips_access`、`vars.example` 的 `NODE_IPS`/`MYSQL_ROOT_PASS`/`DB_PASS` |
| [verify-acceptance-centos7-doc.sh](verify-acceptance-centos7-doc.sh) | 静态校验 [ACCEPTANCE-CENTOS7-DEPLOY.md](../docs/ACCEPTANCE-CENTOS7-DEPLOY.md) 含 Cron webhook/`--task`、grant-nodes、`redi_node` 等关键表述 |
| [verify-deploy-centos7-doc.sh](verify-deploy-centos7-doc.sh) | 静态校验 [DEPLOY-centos7.md](../DEPLOY-centos7.md) / 绿场说明与 `deploy-*.sh`、`redi_admin.sql`、Cron 时刻一致 |
| [verify-product-docs.sh](verify-product-docs.sh) | 产品文档集存在、已移除 AUDIT/开发进度主文档且无残留链接 |
| [verify-launch-checklist-doc.sh](verify-launch-checklist-doc.sh) | `LAUNCH-CHECKLIST` 与 PRODUCTION / node|master README 互链 |
| [verify-redi-artisan-cron.sh](verify-redi-artisan-cron.sh) | `php artisan … --help` 确认 Cron/运维引用的 `redi:*` 命令已注册（含 `redi:health --notify`） |
| [verify-node-skip-api-invariants.sh](verify-node-skip-api-invariants.sh) | 节点数据面 `skip_url` / `bump_skip` 与主机名键约定 |
| [verify-node-api-js-invariants.sh](verify-node-api-js-invariants.sh) | 节点 `/c.js`、`/api.js` 限速与 redirector 出站 URL 约定 |
| [verify-redirect-conn-limit.sh](verify-redirect-conn-limit.sh) | 80/443 连接槽预占、`redirect` 消毒与 HTTP→HTTPS 前置条件 |
| [verify-lagran-iptables-invariants.sh](verify-lagran-iptables-invariants.sh) | lagran `Enabled` / `setIptable` 中止、`REDI_LAGRAN_DISABLED` 与 ip6tables 告警 |
| [verify-console-dns-sync-doc.sh](verify-console-dns-sync-doc.sh) | [CONSOLE.md](../docs/CONSOLE.md) DNS API 同步失败运维段落 |
| [verify-customer-cname-doc.sh](verify-customer-cname-doc.sh) | [CUSTOMER-CNAME-HTTPS.md](../docs/CUSTOMER-CNAME-HTTPS.md) 与 CONSOLE 互链 |
| [verify-masteragent-invariants.sh](verify-masteragent-invariants.sh) | Go masteragent：证书拉取、ack 与注册状态约定 |
| [verify-acme-code-invariants.sh](verify-acme-code-invariants.sh) | 静态校验 ACME 签发/HTTP-01/挑战 upsert 与节点 `GetSkipUrl` 使用 `acmeHostKey`/`AcmeHostKey` |
| [verify-renew-ops-invariants.sh](verify-renew-ops-invariants.sh) | 证书续期 cron 与 ops-webhook 脱敏约定 |
| [verify-acme-prune-requeue-invariants.sh](verify-acme-prune-requeue-invariants.sh) | ACME 挑战清理、prune cron 与 failed 部署重排队约定 |
| [verify-edge-dns-invariants.sh](verify-edge-dns-invariants.sh) | Edge `syncAll` 无 IPv4 快速失败、A/AAAA reconcile、Cloudflare 橙云 AAAA 清理 |
| [verify-dns-providers-invariants.sh](verify-dns-providers-invariants.sh) | DNS API 驱动与 Edge 同步工厂约定 |
| [verify-cname-cert-job-invariants.sh](verify-cname-cert-job-invariants.sh) | CNAME 预检与 `IssueDomainCertificateJob` 签发链约定 |
| [verify-redirect-platform-invariants.sh](verify-redirect-platform-invariants.sh) | PHP/Go 跳转 URL、`force HTTPS` 与平台 DNS 表单约定 |
| [verify-acceptance-edge-pool-doc.sh](verify-acceptance-edge-pool-doc.sh) | 静态校验 [ACCEPTANCE-EDGE-POOL.md](../docs/ACCEPTANCE-EDGE-POOL.md) F 项与 Cron/Pool AAAA 表述 |
| [verify-tenant-edge-queue-invariants.sh](verify-tenant-edge-queue-invariants.sh) | 租户 `pool_host` 变更失效 `dns_synced_at`、`IssueDomainCertificateJob` 双锁、CNAME 预检与 OPS 文档 |
| [verify-health-tenant-invariants.sh](verify-health-tenant-invariants.sh) | `redi:health` / 租户首页 / 批量域名跨租户与展示安全约定 |
| [verify-cert-deploy-invariants.sh](verify-cert-deploy-invariants.sh) | `reconcileCertDeployStatus` / `certIsNodePullable`、启用守卫、`csvSafeCell` |
| [verify-production-readiness-doc.sh](verify-production-readiness-doc.sh) | 静态校验 [PRODUCTION-READINESS.md](../docs/PRODUCTION-READINESS.md) 关键验收表述 |
| [verify-admin-api-invariants.sh](verify-admin-api-invariants.sh) | 后台登录与节点 Agent API 限速约定 |
| [verify-admin-i18n-invariants.sh](verify-admin-i18n-invariants.sh) | 后台 `app/Admin` / `app/Models` 禁止字符串字面量中文、`config/admin.php` 英文回退、locale 中间件与登录同步约定；域名批量/启用校验不得走 `RediErrorMessage` |
| [verify-redi-lang-parity.sh](verify-redi-lang-parity.sh) | `redi-*` 与 Dcat `menu`/`global`/`extension` 三语键对齐（同 `RediLangParityTest`，无 composer 可跑） |
| [verify-redi-errors-invariants.sh](verify-redi-errors-invariants.sh) | `en/redi-errors.php` 与 `RediErrorMessage::get` / `RediCertLastError` 引用一一对应（无死键、无缺键） |
| [verify-redi-cert-error-code-invariants.sh](verify-redi-cert-error-code-invariants.sh) | 禁止用 `last_error ===` 全文做分支；证书错误须走 `RediCertLastError` |
| [verify-ops-english-invariants.sh](verify-ops-english-invariants.sh) | 运维层 `Console` / `Support` / `Http` / `Jobs` / `Services` 禁止字符串字面量中文（DNSPod 中国线路名在 `config/redi.php`） |
| [verify-release-workflow-invariants.sh](verify-release-workflow-invariants.sh) | `release.yml` 与 `build-*-tar` / 冒烟门禁约定 |
| [verify-ci-preflight-parity.sh](verify-ci-preflight-parity.sh) | `dev-preflight` 中的 `verify-*.sh` 须在 `ci.yml` / `release.yml` 出现 |
| [patch-master-cron-ops-webhook.sh](patch-master-cron-ops-webhook.sh) | **仅** Cron 落后于当前模板的已上线主控（绿场 `deploy-master.sh` 已写入正确 cron，**首装勿用**）；升级 `/etc/cron.d/redi-renew`（默认 APP `/opt/301/master`） |
| [centos7/vars.example.env](centos7/vars.example.env) | 部署变量模板（`vars.env`）；Webhook URL 写在主控 `.env`，见文件内注释 |
| [centos7/deploy-master.sh](centos7/deploy-master.sh) / [deploy-node.sh](centos7/deploy-node.sh) | CentOS 7 主控/节点一键部署 |
| [centos7/grant-nodes-from-vars.sh](centos7/grant-nodes-from-vars.sh) | 按 `vars.env` 的 `NODE_IPS` 同步 MySQL + firewalld（**root**；`MYSQL_ROOT_PASS` 读 vars，不重装主控；`NODE_IPS` 为空时撤销已记录 IP，同 `deploy-master`） |
| [centos7/grant-node-mysql.sh](centos7/grant-node-mysql.sh) / [revoke-node-mysql.sh](centos7/revoke-node-mysql.sh) | 单节点 IP 授权/撤销（**root** + `MYSQL_ROOT_PASSWORD`） |
| [centos7/firewalld-allow-mysql-from-node.sh](centos7/firewalld-allow-mysql-from-node.sh) / [firewalld-revoke-mysql-from-node.sh](centos7/firewalld-revoke-mysql-from-node.sh) | 单节点 IP 放行/撤销主控 3306（**root**） |
| [centos7/apply-mysql-skip-name-resolve.sh](centos7/apply-mysql-skip-name-resolve.sh) | 关闭 MySQL 反向 DNS 解析（生产建议） |
| [centos7/run-on-centos7-host.sh](centos7/run-on-centos7-host.sh) | 开发机 Docker/Podman 跑 CentOS 7 容器冒烟 |
| [centos7/lagran-diagnose.sh](centos7/lagran-diagnose.sh) / [lagran-cleanup-iptables.sh](centos7/lagran-cleanup-iptables.sh) | 节点 lagran 诊断与 iptables 清理 |
| [centos7/lagran_ports.py](centos7/lagran_ports.py) | 从 `config.json` 解析 lagran/监听端口（含 `REDI_HTTP_LISTEN` / `REDI_TLS_LISTEN`；cleanup/diagnose 在 `source env.sh` 后与进程一致） |
| [systemd/](systemd/) | `redi-queue` unit 示例 |

CentOS 生产路径：**分步教程** [docs/DEPLOY-TUTORIAL-CENTOS7.md](../docs/DEPLOY-TUTORIAL-CENTOS7.md)；脚本说明 [DEPLOY-centos7.md](../DEPLOY-centos7.md)。
