# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。

主控源码在 `master/master/`。**仓库内不提交发布包**（`*.tar.gz` 已 gitignore）；需要部署时在本地执行 `scripts/build-master-tar.sh` / `scripts/build-node-tar.sh`（打 tag 或上传服务器前建议先 `bash scripts/dev-preflight.sh`），或打 **`v*` tag** 由 GitHub Actions（与 CI 同门槛的测试 + CentOS 7 冒烟）构建并上传到 [Releases](https://github.com/freejbgo/301/releases)。

**生产环境仅支持 CentOS 7.9（及其他 7.x）**。主控与节点使用 [scripts/centos7/deploy-master.sh](scripts/centos7/deploy-master.sh) 与 [scripts/centos7/deploy-node.sh](scripts/centos7/deploy-node.sh)，步骤见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。

| 文档 | 内容 |
|------|------|
| [docs/README.md](docs/README.md) | **文档索引**（按上线 / 运营 / 开发场景） |
| [DEPLOY-centos7.md](DEPLOY-centos7.md) | CentOS 7 一键部署（生产唯一路径） |
| [docs/PRODUCTION-READINESS.md](docs/PRODUCTION-READINESS.md) | 上线阻塞项与验收命令 |
| [docs/CONSOLE.md](docs/CONSOLE.md) | 控制台操作与平台边界 §8 |
| [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md) | 已上线环境增量升级 |
| [SECURITY.md](SECURITY.md) | 密码、端口、Agent |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | 发版前 `dev-preflight` |
