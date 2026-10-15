# 主控

程序在子目录 `master/`。发布包是同级的 `master.tar.gz`，解压后得到这个 `master/` 目录。包里带有 `vendor`，没有 `.env`。

CentOS 7 自动部署把包解压到 `/opt/301/master`，网站根目录是 `/opt/301/master/public`。

克隆整个仓库看源码时，网站根目录是 `master/master/public`，`php artisan` 的工作目录是 `master/master`。重新打包：`./scripts/build-master-tar.sh`。

安装按 [../DEPLOY.md](../DEPLOY.md)。CentOS 7 命令见 [../DEPLOY-centos7.md](../DEPLOY-centos7.md)。

后台怎么操作见 [../docs/CONSOLE.md](../docs/CONSOLE.md)。安全项见 [../SECURITY.md](../SECURITY.md)。

PHP **8.0.2+**（CentOS 7 脚本装 Remi 8.0；CI/打包用 8.4）。队列、证书续期和 `storage:link` 都在部署教程里。
