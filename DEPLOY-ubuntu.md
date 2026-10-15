# Ubuntu 部署

**支持：Ubuntu 22.04 LTS、24.04 LTS**（`scripts/ubuntu/deploy-*.sh` 会校验 `ID=ubuntu` 与版本）。推荐与 CentOS 7 相同拓扑：一台主控 + 多台节点。

| 机器 | 脚本 | 安装路径 |
|------|------|----------|
| 主控 | `scripts/ubuntu/deploy-master.sh` | `/opt/301/master` |
| 节点 | `scripts/ubuntu/deploy-node.sh` | `/opt/redi-multi/redi-multi` |

与 CentOS 版的差异：**防火墙用 ufw**（不用 firewalld）、**PHP-FPM 用户为 www-data**、**MariaDB 配置在 `/etc/mysql/mariadb.conf.d/`**。业务逻辑、数据库绿场导入、节点静态二进制与 [DEPLOY-centos7.md](DEPLOY-centos7.md) 一致。

发布包：本地 `scripts/build-master-tar.sh` / `scripts/build-node-tar.sh`，或 `v*` tag Release。勿把 tar 提交 git。填空示例见 [DEPLOY-ubuntu-example.md](DEPLOY-ubuntu-example.md)。验收见 [docs/ACCEPTANCE-UBUNTU-DEPLOY.md](docs/ACCEPTANCE-UBUNTU-DEPLOY.md)。

## 1. 准备 vars.env

```bash
cd /path/to/301   # 仓库目录，不要克隆到 /opt/301
cp scripts/ubuntu/vars.example.env scripts/ubuntu/vars.env
chmod 600 scripts/ubuntu/vars.env
vi scripts/ubuntu/vars.env
```

字段说明与 CentOS 版相同（`MASTER_IP`、`DB_PASS`、`NODE_IPS`、`REDI_MASTER_URL` 等），见 [scripts/ubuntu/vars.example.env](scripts/ubuntu/vars.example.env)。

- 主控尚无 HTTPS：`MASTER_URL=http://IP`、`ADMIN_HTTPS=false`。
- 节点 Agent 生产须 `https://`；lab 可 `REDI_MASTER_INSECURE=1` + `http://`。

## 2. 部署主控

```bash
sudo bash scripts/ubuntu/deploy-master.sh
```

脚本会：

1. 安装 Nginx、MariaDB、PHP **8.1**（22.04）或 **8.3**（24.04）。
2. 设置 root 密码、`bind-address=0.0.0.0`、`skip-name-resolve`。
3. 空库导入 `db/redi_admin.sql`（含 `redi_node_bump_skip`）。
4. 解压 `master.tar.gz`，`migrate`、队列 `redi-queue`、cron（03:10 续期 / 04:10 清理 ACME，**勿**再配 `schedule:run`）。
5. **ufw** 开放 22/80/443；3306 仅对 `NODE_IPS` 放行。

重复执行保留已有 `.env` / `APP_KEY`。Nginx 站点 `/etc/nginx/sites-available/301-master.conf` 带 `301-master-managed` 时可被脚本刷新；手工 HTTPS 请去掉该标记或另建站点。

后台：`http://主控IP/admin`，初始账号见 [DEPLOY.md](DEPLOY.md)。

多台 `queue:work` 时主控 `.env` 设 **`CACHE_DRIVER=redis`**。

## 3. 部署节点

主控 `NODE_IPS` 含该节点公网 IP 并重跑 `deploy-master.sh` 后，在节点上：

```bash
sudo bash scripts/ubuntu/deploy-node.sh
```

节点包为 **linux/amd64** 静态二进制；`setcap cap_net_admin`、lagran 默认开启。更新 Go 代码后须重新 `build-node-tar.sh` 并部署。**重复执行** `deploy-node.sh` 会 `systemctl restart redi-multi`（应用新 token / 二进制），与 CentOS 节点脚本一致。

## 4. 后台与 DNS

与 [docs/CONSOLE.md](docs/CONSOLE.md) 相同。API DNS 同步行为见 [SECURITY.md](SECURITY.md)。

## 5. 手工维护命令

```bash
# 按 vars.env 批量授权节点 IP
sudo bash scripts/ubuntu/grant-nodes-from-vars.sh

# 单 IP
sudo MYSQL_ROOT_PASSWORD='root密码' bash scripts/ubuntu/grant-node-mysql.sh '节点IP' 'DB_PASS'

# 仅 skip-name-resolve
sudo bash scripts/ubuntu/apply-mysql-skip-name-resolve.sh
```

## 6. 与通用文档的关系

- 未自动化部分（手工 Nginx HTTPS、非 LTS 版本）仍可参考 [DEPLOY.md](DEPLOY.md) 通用章节。
- **生产若以 CentOS 7 脚本为标杆验收**，Ubuntu 环境请自行完成 [docs/ACCEPTANCE-UBUNTU-DEPLOY.md](docs/ACCEPTANCE-UBUNTU-DEPLOY.md) 与 [docs/ACCEPTANCE-EDGE-POOL.md](docs/ACCEPTANCE-EDGE-POOL.md) F1–F8。
