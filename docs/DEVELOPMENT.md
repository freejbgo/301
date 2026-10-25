# 开发与发版自检

仓库布局：**主控** `master/master/`（Laravel + Dcat）、**节点** `node/redi-multi/`（Go）、**库表** `db/redi_admin.sql`、**CentOS 7 部署** `scripts/centos7/`。

平台能力与 API 说明见 [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)。**生产只在 CentOS 7.x 裸机**跑 `deploy-*.sh`，不需要 Docker。真机验收见 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md)、[ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)。

## 发版前一键检查

在**发版机**（任意 Linux，有 composer + Go；无 Docker 也可以）执行。与 GitHub **CI** / **`v*` release** 核心门禁对齐：`node` + `master-phpunit`（8.4/8.0）+ `centos7-scripts`（含 `verify-*`）；Docker 仅用于可选的 DB 容器校验与 CentOS 7 容器冒烟，**跳过不影响在 7.9 真机上线**。

```bash
bash scripts/dev-preflight.sh
```

`verify-*` 索引见 [scripts/README.md](../scripts/README.md)。

**后台三语 / 运维英文防回退**（改 `app/Admin`、`app/Models`、`app/Services` 或 CLI 文案时建议单独跑）：

```bash
bash scripts/verify-admin-i18n-invariants.sh
bash scripts/verify-ops-english-invariants.sh
```

详见 [I18N-DEVELOPMENT-REPORT.md](I18N-DEVELOPMENT-REPORT.md)。

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

`verify-redi-db-deploy-path.sh` 用临时 MariaDB 验证 `utf8mb4_unicode_ci` 建库 + `redi_admin.sql` 导入后 `CALL redi_node_bump_skip` 可用。

**打发布包（推荐上线取包方式）：** 见 [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md) — `bash scripts/build-release-tarballs.sh`（或 `build-*-tar.sh`）；`deploy-*.sh` 在开发机上缺包时会经 `ensure-release-tarballs.sh` 尝试本地构建。GitHub Release（`v*` tag）为可选项。

PHPUnit 以 `phpunit` **退出码**为准；条数随 `main` 演进，勿手抄固定数字。
