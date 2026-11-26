# 开发与发版自检

**首次装生产机房** 请从 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md) 开始；本文面向改代码、打 tar、与 CI 对齐。

仓库布局：**主控** `master/master/`（Laravel + Dcat）、**节点** `node/redi-multi/`（Go）、**库表** `db/redi_admin.sql`、**CentOS 7 部署** `scripts/centos7/`。

平台能力与 API 说明见 [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)。**生产只在 CentOS 7.x 裸机**跑 `deploy-*.sh`，不需要 Docker。真机验收见 [ACCEPTANCE.md](ACCEPTANCE.md)。

## 发版前一键检查

在**发版机**（任意 Linux，有 composer + Go）执行。与 GitHub **CI** / **`v*` release** 对齐：

| CI job | 内容 |
|--------|------|
| **`dev-preflight`** | 本脚本全量（Go + PHPUnit 8.4 + `composer audit` + 全部 `verify-*` + Docker DB 冒烟） |
| **`master-phpunit-php80`** | PHP 8.0 双跑 PHPUnit |
| **`centos7-integration`** | 打 tar + CentOS 7 容器冒烟 |

发版机无 Docker 时 `verify-redi-db-deploy-path.sh` 会跳过；**CI 上会通过 `VERIFY_REDI_REQUIRE_DOCKER=1` 强制跑通**。

```bash
bash scripts/dev-preflight.sh
```

`verify-*` 索引见 [scripts/README.md](../scripts/README.md)。

**后台三语 / 运维英文防回退**（改 `app/Admin`、`app/Models`、`app/Services` 或 CLI 文案时建议单独跑）：

```bash
bash scripts/verify-admin-i18n-invariants.sh
bash scripts/verify-redi-lang-parity.sh
bash scripts/verify-redi-errors-invariants.sh
bash scripts/verify-redi-cert-error-code-invariants.sh
bash scripts/verify-ops-english-invariants.sh
```

约定说明见 [I18N.md](I18N.md)。

## 控制台与 Artisan 的租户边界

后台 HTTP 请求里，带 `BelongsToAdminTenant` 的模型（如域名、分组）会自动按当前登录 `admin_id` 过滤。**Artisan 命令、队列 Job、Webhook 回调没有登录态**，不会套用该全局作用域；读写租户数据须继续走 `*Owned` / `AdminTenantContext` 等既有校验，避免在 CLI 或出站任务里跨租户泄漏。证书行 `RediDomainCert` 无该 trait，归属以关联域名与证书服务层过滤为准。

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

**打发布包：** 见 [RELEASE-PACKAGES.md](RELEASE-PACKAGES.md)。`bash scripts/build-release-tarballs.sh`（或 `build-*-tar.sh`）会把包复制到 `pkg/`，一条命令安装用的就是这两份，要提交。`master/master.tar.gz` 和 `node/redi-multi.tar.gz` 不提交。`deploy-*.sh` 在开发机上缺包时会经 `ensure-release-tarballs.sh` 尝试本地构建。

PHPUnit 以 `phpunit` **退出码**为准；条数随 `main` 演进，勿手抄固定数字。

## 开发机手工部署（非 CentOS 生产）

机房 **CentOS 7 生产** 请用 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md) 或 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 的 `deploy-*.sh`，不要按本节在服务器上克隆仓库。

仅在开发机手搓环境调试时：

1. **数据库**：`mysql < db/mysql_setup.example.sql`，再 `mysql redi_admin < db/redi_admin.sql`（须含 `redi_node_bump_skip`）。
2. **主控**：`cd master/master`，复制 `.env.example` → `.env`，`composer install`，`php artisan key:generate`，`php artisan migrate --force`，`php artisan storage:link`。
3. **队列**：`php artisan queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600`。
4. **节点**：本地 `./scripts/build-node-tar.sh` 或 `go build` 后按 [node/README.md](../node/README.md) 配置 `env.sh` 与 `config.json`。

网站根为 `master/master/public`；与生产路径 `/opt/301/master` 不同，勿混用 cron 示例路径。
