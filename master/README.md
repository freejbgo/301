# 主控

程序在子目录 `master/`。发布包是同级的 `master.tar.gz`，解压后得到这个 `master/` 目录。包里带有 `vendor`，没有 `.env`。

**文档：** 安装 [../docs/DEPLOY-BEGINNER-CENTOS7.md](../docs/DEPLOY-BEGINNER-CENTOS7.md) · 脚本 [../DEPLOY-centos7.md](../DEPLOY-centos7.md) · 后台 [../docs/CONSOLE.md](../docs/CONSOLE.md)（含平台边界 §8）· 架构 [../docs/PLATFORM-ARCHITECTURE.md](../docs/PLATFORM-ARCHITECTURE.md) · 上线清单 [../docs/LAUNCH-CHECKLIST.md](../docs/LAUNCH-CHECKLIST.md) · 安全 [../SECURITY.md](../SECURITY.md)

CentOS 7 自动部署把包解压到 `/opt/301/master`，网站根目录是 `/opt/301/master/public`。

克隆整个仓库看源码时，网站根目录是 `master/master/public`，`php artisan` 的工作目录是 `master/master`。发版前在仓库根目录跑 `bash scripts/dev-preflight.sh`，再 `./scripts/build-release-tarballs.sh`（或仅主控时 `./scripts/build-master-tar.sh`）。一条命令安装用提交进仓库的 `pkg/master.tar.gz`；手工部署的 `REDI_MASTER_TAR` 见 [../docs/RELEASE-PACKAGES.md](../docs/RELEASE-PACKAGES.md)。

## 运行要求

- PHP **8.0.2+**（CentOS 7 脚本装 Remi 8.0；CI/打包用 8.4）
- 队列：`QUEUE_CONNECTION=database` 或 **redis**（多 worker 时须 `CACHE_DRIVER=redis`，见 [OPS-MULTI-WORKER-QUEUE.md](../docs/OPS-MULTI-WORKER-QUEUE.md)）
- 续期 / 清理 / 健康：CentOS 由 `/etc/cron.d/redi-renew` 直调 artisan（**勿** `schedule:run`）
- 运营自检：`php artisan redi:health` / `--json` / `--notify`；各 `code` 见 [HEALTH-REFERENCE.md](../docs/HEALTH-REFERENCE.md)

## 产品边界（摘要）

- 边缘池 **模型 2**（token→pool→多 A）；不提供 NS 委托、多 pool、逐域 TXT 主流程。
- 域名默认停用，启用前 DNS 预检；跳转页为 **200+JS**，强制 HTTPS 为 TLS **301**。

完整说明见 [CONSOLE.md](../docs/CONSOLE.md) §8。
