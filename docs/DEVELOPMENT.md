# 开发与发版自检

仓库布局：**主控** `master/master/`（Laravel + Dcat）、**节点** `node/redi-multi/`（Go）、**库表** `db/redi_admin.sql`、**CentOS 7 部署** `scripts/centos7/`。

平台能力与 API 说明见 [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)。真机验收见 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md)、[ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)。

## 发版前一键检查

与 GitHub **CI** / **`v*` release** 核心门禁对齐：`node` + `master-phpunit`（8.4/8.0）+ `centos7-scripts`（含 `verify-*`）+ 可选 CentOS 7 容器冒烟。

```bash
bash scripts/dev-preflight.sh
```

`verify-*` 索引见 [scripts/README.md](../scripts/README.md)。

## 分步（调试时）

```bash
cd node/redi-multi && go vet ./... && go test ./...
cd master/master && composer install && composer audit --no-interaction && ./vendor/bin/phpunit --testsuite Unit,Feature
bash scripts/verify-redi-artisan-cron.sh
# 其余 scripts/verify-*-invariants.sh、verify-*-doc.sh 见 dev-preflight.sh
php scripts/verify-redi-node-procedure-sync.php
bash scripts/verify-redi-db-deploy-path.sh   # 需 Docker；无 docker 时 dev-preflight 会跳过
bash scripts/verify-ci-preflight-parity.sh
```

`verify-redi-db-deploy-path.sh` 用临时 MariaDB 验证 `utf8mb4_unicode_ci` 建库 + `redi_admin.sql` 导入后 `CALL redi_node_bump_skip` 可用。发版 tar：`scripts/ensure-release-tarballs.sh`（`build-master-tar.sh` / `build-node-tar.sh`）。

PHPUnit 以 `phpunit` **退出码**为准；条数随 `main` 演进，勿手抄固定数字。
