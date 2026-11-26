# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

**阅读姿势：** 本产品按 **绿场（全新机房）** 交付——见 [docs/GREENFIELD-PRODUCT.md](docs/GREENFIELD-PRODUCT.md)。文档与 `deploy-*.sh` 描述的是 **当前版本** 的安装与运营，不含迁移史、二次开发进度或已删除的 AUDIT 专章。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。

主控源码在 `master/master/`。一条命令安装用的包在 [pkg/master.tar.gz](pkg/master.tar.gz) 和 [pkg/redi-multi.tar.gz](pkg/redi-multi.tar.gz)，已经打好并提交。开发机临时产物 `master/master.tar.gz`、`node/redi-multi.tar.gz` 仍不提交。分步安装仍可在发版机 `bash scripts/dev-preflight.sh` 后 `bash scripts/build-release-tarballs.sh`，再 `scp` 到机房（见 [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md)）。

**生产环境仅支持 CentOS 7.9（及其他 7.x）裸机**，用 `yum` + `deploy-*.sh`，**无需 Docker**。仓库公开后，主控不用填 IP，一条命令：

```bash
curl -fsSL https://github.com/freejbgo/301/raw/main/master.sh -o master.sh && chmod +x master.sh && bash master.sh
```

说明见 [docs/DEPLOY-BEGINNER-CENTOS7.md](docs/DEPLOY-BEGINNER-CENTOS7.md)。分步教程见 [docs/DEPLOY-TUTORIAL-CENTOS7.md](docs/DEPLOY-TUTORIAL-CENTOS7.md)；脚本见 [scripts/centos7/deploy-master.sh](scripts/centos7/deploy-master.sh)、[scripts/centos7/deploy-node.sh](scripts/centos7/deploy-node.sh)、[DEPLOY-centos7.md](DEPLOY-centos7.md)。

| 文档 | 内容 |
|------|------|
| [docs/GREENFIELD-PRODUCT.md](docs/GREENFIELD-PRODUCT.md) | **绿场产品说明**（当全新系统读文档） |
| [docs/README.md](docs/README.md) | **文档索引**（按上线 / 运营 / 开发场景） |
| [docs/DEPLOY-BEGINNER-CENTOS7.md](docs/DEPLOY-BEGINNER-CENTOS7.md) | **主控一条命令安装**（`master.sh`） |
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
