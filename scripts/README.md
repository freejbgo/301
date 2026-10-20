# 运维与发版脚本索引

| 脚本 | 用途 |
|------|------|
| [dev-preflight.sh](dev-preflight.sh) | 发版前自检（Go + PHPUnit + 脚本语法 + verify），见 [docs/AUDIT-CENTOS7-PRODUCTION.md](../docs/AUDIT-CENTOS7-PRODUCTION.md) §9 |
| [build-master-tar.sh](build-master-tar.sh) / [build-node-tar.sh](build-node-tar.sh) | 打主控/节点发布包 |
| [ensure-release-tarballs.sh](ensure-release-tarballs.sh) | `deploy-*.sh` 共用：缺 tar 时尝试本地构建 |
| [verify-redi-db-deploy-path.sh](verify-redi-db-deploy-path.sh) | Docker 临时 MariaDB：建库 collation + `redi_admin.sql` + `CALL redi_node_bump_skip`（CI/`VERIFY_REDI_REQUIRE_DOCKER=1` 时无 docker 会失败） |
| [verify-redi-node-procedure-sync.php](verify-redi-node-procedure-sync.php) | SQL dump 与迁移 `100009` 存储过程一致 |
| [verify-master-cron-templates.sh](verify-master-cron-templates.sh) | 静态校验 `deploy-master` / `patch-master-cron` 的 Cron 模板（无 `schedule:run`、无 `master/master` 路径、三条任务 + `ops-webhook` 兜底、`--max-time=3600`） |
| [verify-node-deploy-templates.sh](verify-node-deploy-templates.sh) | 静态校验 `deploy-node`、`vars.example.env`、`env.example` 与 lagran 脚本（HTTPS Agent、静态 ldd、`redi_node`、setcap、lagran_ports.py、`REDI_DB_PASSWORD`） |
| [verify-grant-nodes-templates.sh](verify-grant-nodes-templates.sh) | 静态校验 `grant-nodes-from-vars` 与 `deploy-master` 共用 `redi_sync_node_ips_access`、`vars.example` 的 `NODE_IPS`/`MYSQL_ROOT_PASS`/`DB_PASS` |
| [patch-master-cron-ops-webhook.sh](patch-master-cron-ops-webhook.sh) | 已上线主控：升级 `/etc/cron.d/redi-renew`（默认 APP `/opt/301/master`；可传参 `bash …/patch-master-cron-ops-webhook.sh <artisan目录>`）；结束会打印 Cron / `redi:health --notify` 验收提示 |
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

CentOS 生产路径见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)。
