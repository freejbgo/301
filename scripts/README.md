# 运维与发版脚本索引

| 脚本 | 用途 |
|------|------|
| [dev-preflight.sh](dev-preflight.sh) | 发版前自检（Go + PHPUnit + 脚本语法 + verify），见 [docs/AUDIT-CENTOS7-PRODUCTION.md](../docs/AUDIT-CENTOS7-PRODUCTION.md) §9 |
| [build-master-tar.sh](build-master-tar.sh) / [build-node-tar.sh](build-node-tar.sh) | 打主控/节点发布包 |
| [ensure-release-tarballs.sh](ensure-release-tarballs.sh) | `deploy-*.sh` 共用：缺 tar 时尝试本地构建 |
| [verify-redi-db-deploy-path.sh](verify-redi-db-deploy-path.sh) | Docker 临时 MariaDB：建库 collation + `redi_admin.sql` + `CALL redi_node_bump_skip`（CI/`VERIFY_REDI_REQUIRE_DOCKER=1` 时无 docker 会失败） |
| [verify-redi-node-procedure-sync.php](verify-redi-node-procedure-sync.php) | SQL dump 与迁移 `100009` 存储过程一致 |
| [patch-master-cron-ops-webhook.sh](patch-master-cron-ops-webhook.sh) | 已上线主控：升级 `/etc/cron.d/redi-renew`（默认 APP `/opt/301/master`） |
| [centos7/](centos7/) | CentOS 7 一键部署、防火墙、MySQL 授权、lagran 诊断/清理 |
| [systemd/](systemd/) | `redi-queue` unit 示例 |

CentOS 生产路径见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)。
