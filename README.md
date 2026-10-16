# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。

主控源码在 `master/master/`。**仓库内不提交发布包**（`*.tar.gz` 已 gitignore）；需要部署时在本地执行 `scripts/build-master-tar.sh` / `scripts/build-node-tar.sh`，或打 **`v*` tag** 由 GitHub Actions（与 CI 同门槛的测试 + CentOS 7 冒烟）构建并上传到 [Releases](https://github.com/freejbgo/301/releases)。

**生产环境仅支持 CentOS 7.9（及其他 7.x）**。主控与节点使用 [scripts/centos7/deploy-master.sh](scripts/centos7/deploy-master.sh) 与 [scripts/centos7/deploy-node.sh](scripts/centos7/deploy-node.sh)，步骤见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。

| 文档 | 内容 |
|------|------|
| [DEPLOY.md](DEPLOY.md) | 部署教程（通用 / 手工） |
| [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md) | 绿场安装（无旧数据、一次导入 `redi_admin.sql`） |
| [DEPLOY-centos7.md](DEPLOY-centos7.md) | CentOS 7 一键脚本与变量 |
| [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md) | CentOS 7.9 真机部署验收清单 |
| [docs/AUDIT-CENTOS7-PRODUCTION.md](docs/AUDIT-CENTOS7-PRODUCTION.md) | 生产复核（P0/P1 与待办 P2） |
| [docs/CONSOLE.md](docs/CONSOLE.md) | 控制台操作手册 |
| [docs/CUSTOMER-CNAME-HTTPS.md](docs/CUSTOMER-CNAME-HTTPS.md) | 交给客户的 CNAME 说明 |
| [SECURITY.md](SECURITY.md) | 上线前要改的密码和端口 |
