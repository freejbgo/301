# 主控

程序在子目录 `master/`。发布包是同级的 `master.tar.gz`，解压后得到这个 `master/` 目录，里面没有 `vendor` 和 `.env`。

克隆整个仓库时，网站根目录是 `master/master/public`，`php artisan` 的工作目录是 `master/master`。

安装按 [../DEPLOY.md](../DEPLOY.md)。CentOS 7 命令见 [../DEPLOY-centos7.md](../DEPLOY-centos7.md)。

后台怎么操作见 [../docs/CONSOLE.md](../docs/CONSOLE.md)。安全项见 [../SECURITY.md](../SECURITY.md)。

PHP 使用 7.3 或 8.0。队列、证书续期和 `storage:link` 都在部署教程里。
