# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。

主控源码在 `master/master/`。**仓库内不提交发布包**（`*.tar.gz` 已 gitignore）。上线推荐开发机 `bash scripts/dev-preflight.sh` 后 `bash scripts/build-release-tarballs.sh`，`scp` 到机房并在 `vars.env` 配置 `REDI_MASTER_TAR` / `REDI_NODE_TAR`（见 [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md)）。可选从 [GitHub Releases](https://github.com/freejbgo/301/releases) 取同结构 tar（`v*` tag + Actions）。

**生产环境仅支持 CentOS 7.9（及其他 7.x）裸机**，用 `yum` + `deploy-*.sh`，**无需 Docker**。**从零部署请跟** [docs/DEPLOY-TUTORIAL-CENTOS7.md](docs/DEPLOY-TUTORIAL-CENTOS7.md)；脚本见 [scripts/centos7/deploy-master.sh](scripts/centos7/deploy-master.sh)、[scripts/centos7/deploy-node.sh](scripts/centos7/deploy-node.sh)、[DEPLOY-centos7.md](DEPLOY-centos7.md)。

| 文档 | 内容 |
|------|------|
| [docs/README.md](docs/README.md) | **文档索引**（按上线 / 运营 / 开发场景） |
| [docs/DEPLOY-TUTORIAL-CENTOS7.md](docs/DEPLOY-TUTORIAL-CENTOS7.md) | **CentOS 7 分步部署教程（从零开始）** |
| [DEPLOY-centos7.md](DEPLOY-centos7.md) | CentOS 7 脚本说明与可选章节 |
| [docs/LAUNCH-CHECKLIST.md](docs/LAUNCH-CHECKLIST.md) | 一页上线检查单（可打勾） |
| [docs/PRODUCTION-READINESS.md](docs/PRODUCTION-READINESS.md) | 上线说明与验收命令（详情） |
| [docs/CONSOLE.md](docs/CONSOLE.md) | 控制台操作与平台边界 §8 |
| [docs/I18N.md](docs/I18N.md) | 后台三语与运维英文文案约定 |
| [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md) | 绿场库与首登要点 |
| [docs/OPS-RELEASE-UPDATE.md](docs/OPS-RELEASE-UPDATE.md) | **首装后**换发布包（绿场首装可跳过） |
| [SECURITY.md](SECURITY.md) | 密码、端口、Agent |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | 发版前 `dev-preflight` |
