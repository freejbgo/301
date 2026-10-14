# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。

主控源码在 `master/master/`。发布包在 [GitHub Releases](https://github.com/freejbgo/301/releases)：`master.tar.gz`（已含 `vendor`，不含 `.env`）和 `redi-multi.tar.gz`（CentOS 7 可直接运行的静态二进制）。仓库内 tar 由 CI/发版脚本构建校验；发版请以 **tag Release** 或本地执行 `scripts/build-*-tar.sh` 为准。

CentOS 7.9（及其他 7.x）用两个脚本自动安装：[scripts/centos7/deploy-master.sh](scripts/centos7/deploy-master.sh) 和 [scripts/centos7/deploy-node.sh](scripts/centos7/deploy-node.sh)。步骤见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。

| 文档 | 内容 |
|------|------|
| [DEPLOY.md](DEPLOY.md) | 部署教程 |
| [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md) | 绿场安装（无旧数据、migrate 与 SQL 分工） |
| [DEPLOY-centos7.md](DEPLOY-centos7.md) | CentOS 7 命令 |
| [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md) | CentOS 7.9 真机部署验收清单 |
| [docs/CONSOLE.md](docs/CONSOLE.md) | 控制台操作手册 |
| [docs/CUSTOMER-CNAME-HTTPS.md](docs/CUSTOMER-CNAME-HTTPS.md) | 交给客户的 CNAME 说明 |
| [SECURITY.md](SECURITY.md) | 上线前要改的密码和端口 |
