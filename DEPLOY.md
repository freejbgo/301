# 部署教程

按这个顺序做：先主控和数据库，再节点，最后到后台把 DNS、节点和域名配好。控制台里每一步点哪里，见 [docs/CONSOLE.md](docs/CONSOLE.md)。

CentOS 7 的可复制命令见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。把 IP 换成 `1.2.3.4` / `5.6.7.8` 的填空示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。

## 这套程序怎么接流量

客户域名不直接 A 到某一台节点。

1. 客户把自己的域名 **CNAME** 到后台给出的 `{token}.平台主域名`。同一个后台账号下的域名共用这一个目标。
2. `{token}.平台主域名` **CNAME** 到 `pool.平台主域名`。
3. `pool.平台主域名` 写多条 **A**，指向所有启用节点的公网 IPv4。一台机器上的多个 IP 写在同一条节点上，不要为每个 IP 再建一条节点。

证书由主控向 Let's Encrypt 申请（HTTP-01），再下发到全部启用节点。访客访问客户域名时，可能落到池里任意一台节点。

## 机器与端口

| 机器 | 要装的 | 对公网开放 | 不对公网开放 |
|------|--------|------------|----------------|
| 主控 | Nginx、PHP 8.0、MySQL/MariaDB、队列 | 22、80、443 | 3306 |
| 每台节点 | `node/redi-multi.tar.gz` 里的程序 | 22、80、443 | 3306、8080 |

节点仍直连主控上的 MySQL，所以 3306 只允许主控本机和各节点的出口 IP。8080 是旧的脚本端口，跳转页已经改用客户域名上的 `/api.js`、`/c.js`，不必对访客开放 8080。

一台跳转机器只跑一个节点进程、一个 Token。这台机器的其他公网 IP 填在该节点的「其他公网 IP」里。

## 1. 数据库（在主控上）

编辑 `db/mysql_setup.example.sql`，把 `change_this_db_password` 换成强密码。然后：

```bash
mysql -u root -p < db/mysql_setup.example.sql
mysql -u root -p redi_admin < db/redi_admin.sql
```

- 全量 SQL 已包含当前表结构。导入后再执行 `php artisan migrate --force` 也安全，已登记的迁移不会重建表。
- 初始后台账号：用户名 `admin`，密码 `Redi301@Install2026`。登录后马上修改。
- 关闭 MySQL 反向 DNS。CentOS 7 执行 `sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh`。其他系统在 `[mysqld]` 下加入 `skip-name-resolve` 和 `skip-external-locking` 后重启数据库。
- 每台节点的出口 IP 单独授权，不要把 3306 开放给 `0.0.0.0/0`：

```bash
sudo MYSQL_ROOT_PASSWORD='root密码' bash scripts/centos7/grant-node-mysql.sh '节点出口IP' '与 SQL 里相同的库密码'
```

多台节点可以把 IP 写进 `scripts/centos7/vars.env`（从 `vars.example.env` 复制，权限 `600`），再执行 `sudo bash scripts/centos7/grant-nodes-from-vars.sh`。

## 2. 主控

要求 PHP `7.3` 或 `8.0`（`composer.json` 不接受 8.1 及以上）。CentOS 7 使用 Remi 的 PHP 8.0。

```bash
cd master
cp .env.example .env
```

编辑 `.env`：

| 项 | 填写 |
|----|------|
| `APP_URL` | 主控对外地址，有证书后用 `https://` |
| `DB_PASSWORD` | 与上面数据库密码相同 |
| `DB_HOST` | 库在本机就保持 `127.0.0.1` |
| `QUEUE_CONNECTION` | 保持 `database` |
| `ADMIN_HTTPS` | 已有 HTTPS 证书时 `true`；暂时只用 IP 的 HTTP 时先改 `false` |

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
chown -R nginx:nginx storage bootstrap/cache
```

`chown` 的用户按网站运行用户改。Debian/Ubuntu 常见是 `www-data`，CentOS 的 Nginx 常见是 `nginx`。

网站根目录必须是 `master/public`。Nginx 将请求交给 `index.php`。PHP-FPM 的监听地址以 `/etc/php-fpm.d/www.conf`（或发行版对应文件）里的 `listen` 为准，不要猜 sock 路径。

队列要常驻。证书任务会等待全局锁，超时和重试必须用下面这组参数：

```bash
php artisan queue:work --sleep=3 --timeout=320 --tries=100
```

生产环境用 systemd，示例是 `scripts/systemd/redi-queue.service.example`。CentOS 上把其中的 `User=www-data` 改成 `nginx`，并改对 `WorkingDirectory`。

证书续期每天一次：

```bash
10 3 * * * cd /opt/301/master && php artisan redi:renew-certificates >> /var/log/redi-renew.log 2>&1
```

主控域名的 HTTPS 证书用面板或 certbot 签在 Nginx 上。这张证书只保护后台，和客户域名的跳转证书不是同一张。

浏览器打开 `https://主控域名/admin`（或暂时的 `http://主控IP/admin`）。

## 3. 节点

不要在节点上 `go run`，也不要改 `mysqlconnect.go` 里的地址。数据库地址、主控地址和 Token 都放在环境文件里。发布物是仓库里的 `node/redi-multi.tar.gz`。

```bash
sudo mkdir -p /opt/redi-multi
sudo tar xzf redi-multi.tar.gz -C /opt/redi-multi
cd /opt/redi-multi/redi-multi
```

`config.json` 里把 `server_ip` 改成这台机器的主公网 IPv4，`default_redirect_url` 改成未命中域名时的默认页。`addr` 保持 `0.0.0.0:80`，`tls_addr` 保持 `0.0.0.0:443`。

环境文件路径要和 systemd 单元一致。CentOS 7 的 `EnvironmentFile` 不要写 `export`：

```bash
cat > /opt/redi-multi/redi-multi/env.sh <<'EOF'
REDI_DB_HOST=主控IP
REDI_DB_PORT=3306
REDI_DB_USER=redi_admin
REDI_DB_PASSWORD=与主控相同的库密码
REDI_DB_NAME=redi_admin
REDI_MASTER_URL=https://主控域名
REDI_NODE_TOKEN=后台创建节点时只显示一次的 Token
EOF
chmod 600 /opt/redi-multi/redi-multi/env.sh
```

`REDI_NODE_TOKEN` 来自后台「节点管理」新建节点后的提示。丢失就在该节点上轮换 Token，把新值写回 `env.sh` 并重启节点。

lagran 需要 `CAP_NET_ADMIN`：

```bash
sudo setcap cap_net_admin+ep /opt/redi-multi/redi-multi/bin/redi-multi
```

单元文件使用 `scripts/centos7/redi-multi.service.example`。确认 `WorkingDirectory`、`EnvironmentFile`、`ExecStart` 都指向解压后的 `redi-multi` 目录和 `bin/redi-multi`。

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now redi-multi
```

先在主控上为这台机器的出口 IP 做完数据库授权和防火墙放行，再启动节点。自测：

```bash
mysql -h 主控IP -u redi_admin -p -e 'SELECT 1' redi_admin
```

节点程序改过之后，在开发机执行 `./scripts/build-node-tar.sh`，再把新的 `node/redi-multi.tar.gz` 提交。线上部署解压的是这个包。

## 4. 上线顺序

1. 超级管理员打开「DNS 与节点池」，填写平台主域名。手动模式按页面参考表去 DNS 厂商添加 pool 的 A 和租户 CNAME。API 模式保存厂商凭证后点同步。
2. 「节点管理」添加节点。主 IP 填这台机器的主公网 IPv4，其他 IP 按行填在「其他公网 IP」。保存时复制 Token 到该机器的 `env.sh`。
3. 添加或改了 IP 之后，手动模式去厂商改 pool 的 A；API 模式再点一次同步。删除节点不会自动删 DNS 记录。
4. 普通账号在「分组管理」建分组，在「域名管理」添加客户域名。把列表里的 CNAME 目标交给客户。
5. 预检显示「已指向」后，点「申请证书」。队列跑起来之后，状态会从验证中到已生效。

客户自己要做的事见 [docs/CUSTOMER-CNAME-HTTPS.md](docs/CUSTOMER-CNAME-HTTPS.md)。

## 不要这样做

- 不要把客户域名 A 到某一台节点的 IP。
- 不要把同一台机器的每个 IP 各建一条节点。多出来的节点没有自己的进程，证书申请会一直等它上报。
- 不要 `iptables -F` 清空防火墙，也不要定时重启 MySQL。
- 不要把数据库密码写进仓库或 `mysqlconnect.go`。
