# 301

一套 301 跳转程序：主控（`master/`）、节点（`node/`）、数据库（`db/`）。

客户域名 CNAME 到 `{token}.平台主域名`，再 CNAME 到 `pool.平台主域名`。pool 的 A 记录指向启用节点的公网 IP。不要把客户域名 A 到某一台节点。

**生产环境是 CentOS 7.9（其他 7.x 也可以）裸机**，用 `yum` 和已经打好的安装包，不用 Docker。仓库公开后，主控不用填 IP：

```bash
curl -fsSL https://github.com/freejbgo/301/raw/main/master.sh -o master.sh && chmod +x master.sh && bash master.sh
```

节点同样一条命令。主控装完后屏幕会打出完整命令，把后台 Token 贴进去即可。说明见 [docs/DEPLOY-BEGINNER-CENTOS7.md](docs/DEPLOY-BEGINNER-CENTOS7.md)。

安装包在 [pkg/master.tar.gz](pkg/master.tar.gz) 和 [pkg/redi-multi.tar.gz](pkg/redi-multi.tar.gz)。数据库由安装脚本一次导入 `db/redi_admin.sql`。初始后台 `admin` / `Redi301@Install2026`，登录后必须改密码。

主控对公网开 22、80、443。3306 只给节点公网 IP，不要对 `0.0.0.0/0` 开放，也不要 `iptables -F`。密码和 Token 不要写进仓库。

手工改 `vars.env`、看脚本每一步做了什么，见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。生产变量模板：[scripts/centos7/vars.production.example.env](scripts/centos7/vars.production.example.env)。

## 文档

| 文档 | 什么时候看 |
|------|------------|
| [docs/DEPLOY-BEGINNER-CENTOS7.md](docs/DEPLOY-BEGINNER-CENTOS7.md) | 装主控和节点 |
| [DEPLOY-centos7.md](DEPLOY-centos7.md) | 脚本实际做了什么、手工填 `vars.env` |
| [docs/CONSOLE.md](docs/CONSOLE.md) | 后台怎么操作 |
| [docs/CUSTOMER-CNAME-HTTPS.md](docs/CUSTOMER-CNAME-HTTPS.md) | 发给客户的 CNAME 说明 |
| [docs/LAUNCH-CHECKLIST.md](docs/LAUNCH-CHECKLIST.md) | 上线前打勾 |
| [docs/PRODUCTION-READINESS.md](docs/PRODUCTION-READINESS.md) | 打勾项对应的命令 |
| [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) | 逐项核对安装和业务 |
| [docs/OPS-RELEASE-UPDATE.md](docs/OPS-RELEASE-UPDATE.md) | 已经在跑，只换程序 |
| [docs/HEALTH-REFERENCE.md](docs/HEALTH-REFERENCE.md) | `redi:health` 报错码 |
| [docs/OPS-MULTI-WORKER-QUEUE.md](docs/OPS-MULTI-WORKER-QUEUE.md) | 多个队列进程 |
| [docs/NETWORKING-IPv6.md](docs/NETWORKING-IPv6.md) | 要开 IPv6 时 |
| [SECURITY.md](SECURITY.md) | 密码、端口、Agent |
| [docs/I18N.md](docs/I18N.md) | 后台简 / 繁 / 英 |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | 改代码、发版前检查 |
| [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md) | 重新打安装包 |
| [docs/PLATFORM-ARCHITECTURE.md](docs/PLATFORM-ARCHITECTURE.md) | DNS 模型和节点 API |
