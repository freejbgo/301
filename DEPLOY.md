# 部署教程

**适用场景：** 客户域名在部分网络下 **打不开、被重置、访问不稳**，需要 CNAME 到你们边缘，由节点做跳转，并用 **lagran** 调整出站 TCP 窗口，尽量让 80/443 上的跳转页还能被访问。  
**不是**给「完全正常、无任何干扰」的普通 301 用的；那种场景可以关 lagran，但本仓库默认按「有问题域名」来配。

按这个顺序做：先主控和数据库，再节点，最后到后台把 DNS、节点和域名配好。控制台里每一步点哪里，见 [docs/CONSOLE.md](docs/CONSOLE.md)。

**生产仅支持 CentOS 7.9（7.x）**：两个全自动脚本见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。变量示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。真机验收见 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md)；上线清单见 [docs/PRODUCTION-READINESS.md](docs/PRODUCTION-READINESS.md)。

**主控已在跑、只想跟上新能力（Agent 0.1.3、cron Webhook、运营命令等）**：见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md)。能力分级见 [docs/ROADMAP-PRODUCTION.md](docs/ROADMAP-PRODUCTION.md)。

开发阶段 [GitHub Releases](https://github.com/freejbgo/301/releases) 可能暂无包；需部署时在仓库根目录执行 `scripts/build-*-tar.sh`，或发 **`v*` tag** 由 [`.github/workflows/release.yml`](.github/workflows/release.yml) 在通过 Go/PHPUnit（8.4 与 8.0）、`composer audit`、CentOS 7 脚本与容器冒烟后上传 Release（发布前对解压后的主控树再跑 audit + PHPUnit）。本地发版前执行 `bash scripts/dev-preflight.sh`（分步说明见 [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)）。

**绿场（无旧数据）** 时数据库以 **一次导入 `redi_admin.sql`** 为准（含节点存储过程），见 [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md)。下面是通用步骤；CentOS 7 不要按克隆目录手工装，直接跑脚本。

## 这套程序怎么接流量

客户域名不直接 A 到某一台节点。

1. 客户把自己的域名 **CNAME** 到后台给出的 `{token}.平台主域名`。同一个后台账号下的域名共用这一个目标。
2. `{token}.平台主域名` **CNAME** 到 `pool.平台主域名`。
3. `pool.平台主域名` 写多条 **A**，指向所有启用节点的公网 IPv4。一台机器上的多个 IP 写在同一条节点上，不要为每个 IP 再建一条节点。

证书由主控向 Let's Encrypt 申请（HTTP-01），再下发到全部启用节点。访客访问客户域名时，可能落到池里任意一台节点。

## 机器与端口

| 机器 | 要装的 | 对公网开放 | 不对公网开放 |
|------|--------|------------|----------------|
| 主控 | Nginx、PHP 8.0.2+、MySQL/MariaDB、队列 | 22、80、443 | 3306 |
| 每台节点 | 节点发布包（本地 `build-node-tar.sh` 或 Release 的 `redi-multi.tar.gz`） | 22、80、443 | 3306、8080 |

节点仍直连主控上的 MySQL，所以 3306 只允许主控本机和各节点的出口 IP。8080 是调试监听（默认 `127.0.0.1:8080`）；生产跳转走客户域名 **80/443** 上的 `/api.js`、`/c.js`，不必对访客开放 8080。`/api.js` 对公网开放是业务需要，节点侧有 **按 IP** 与 **按 IP+域名参数** 限速（见 [SECURITY.md](SECURITY.md)）。

一台跳转机器只跑一个节点进程、一个 Token。这台机器的其他公网 IP 填在该节点的「其他公网 IP」里。

## 1. 数据库（在主控上）

编辑 `db/mysql_setup.example.sql`，把 `change_this_db_password` 换成强密码。然后：

```bash
mysql -u root -p < db/mysql_setup.example.sql
mysql -u root -p redi_admin < db/redi_admin.sql
```

- **绿场**：`redi_admin.sql` = Dcat 菜单 + 业务表 + 默认 `admin` + **`redi_node_bump_skip` 存储过程**（无需为建库再单独 migrate）。详见 [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md)。
- 导入 SQL 后可用下面命令确认过程已存在；CentOS `deploy-master.sh` 会执行 `migrate --force`（绿场通常无待执行迁移，无需你单独跑 migrate）。已上线跟版本见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md)。
- 验证过程（可选）：

```bash
mysql -u redi_admin -p redi_admin -e \
  "SHOW PROCEDURE STATUS WHERE Db='redi_admin' AND Name='redi_node_bump_skip'"
```

- 初始后台账号：用户名 `admin`，密码 `Redi301@Install2026`；仍为默认密码时除改密页外无法使用后台（`.env` 可设 `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false` 关闭强制改密）。
- 关闭 MySQL 反向 DNS。CentOS 7 执行 `sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh`。其他系统在 `[mysqld]` 下加入 `skip-name-resolve` 和 `skip-external-locking` 后重启数据库。
- 每台节点的出口 IP 单独授权，不要把 3306 开放给 `0.0.0.0/0`：

```bash
sudo MYSQL_ROOT_PASSWORD='root密码' bash scripts/centos7/grant-node-mysql.sh '节点出口IP' '与 SQL 里相同的库密码'
```

多台节点可把 IP 写入 `scripts/centos7/vars.env`（`cp scripts/centos7/vars.example.env scripts/centos7/vars.env`，`chmod 600`），再执行 `sudo bash scripts/centos7/grant-nodes-from-vars.sh`（从列表删 IP 或清空 `NODE_IPS` 后重跑可撤销已记录授权，与 `deploy-master` 相同）。

## 2. 主控

要求 PHP **8.0.2 及以上**（与 `composer.json`、GitHub CI 的 PHP 8.4 构建一致）。CentOS 7 可使用 Remi 的 PHP 8.0/8.2；生产请与发布包 `vendor` 的 PHP 版本匹配。

```bash
cd master/master
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
| `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD` | 默认 `true`：检测到种子默认密码则强制改密 |
| `TRUSTED_PROXIES` | 反代后填代理 IP/CIDR；**生产勿用 `*`**（`APP_ENV=production` 且 `*` 时 **Web 与 `php artisan`/`queue:work` 启动都会失败**，除非 `REDI_ALLOW_TRUST_ALL_PROXIES=true`） |

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
chown -R nginx:nginx storage bootstrap/cache
```

`chown` 的用户按网站运行用户改。CentOS 上 Nginx 常见为 `nginx`。

从仓库手动安装时，网站根目录是 `master/master/public`。CentOS 7 脚本使用发布包，网站根目录是 `/opt/301/master/public`。Nginx 将请求交给 `index.php`。PHP-FPM 的监听地址以 `/etc/php-fpm.d/www.conf`（或发行版对应文件）里的 `listen` 为准，不要猜 sock 路径。

队列要常驻。证书任务会等待全局锁，超时和重试必须用下面这组参数：

```bash
php artisan queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600
```

生产环境用 systemd，示例是 `scripts/systemd/redi-queue.service.example`（默认 `User=nginx`）。请改对 `WorkingDirectory` 与 PHP-FPM 用户一致。

证书续期 / ACME 清理 / 运营自检（CentOS `deploy-master` 写入 `/etc/cron.d/redi-renew`，含 **03:10** 续期、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`）：

`/etc/cron.d/` 里必须写用户名。发布包安装的程序目录是 `/opt/301/master`；git 克隆时把路径换成 `master/master`。生产须 **三行**（03:10 续期、04:10 清理 ACME、08:30 `redi:health --notify`），且建议 `bash -c 'cd … && php artisan … || php artisan redi:ops-webhook …'`（与 `deploy-master` 一致）。health 行在 webhook 已成功上报 issues 时退出 0，避免重复告警；未配置 `REDI_HEALTH_WEBHOOK_URL` 时仍可由 `||` 触发 ops 告警。

完整内容与验收 `grep` 见 `scripts/centos7/deploy-master.sh` 或 [UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md) §4。

主控域名的 HTTPS 证书用面板或 certbot 签在 Nginx 上。这张证书只保护后台，和客户域名的跳转证书不是同一张。

浏览器打开 `https://主控域名/admin`（或暂时的 `http://主控IP/admin`）。

## 3. 节点

不要在节点上 `go run`，也不要改 `mysqlconnect.go` 里的地址。数据库地址、主控地址和 Token 都放在环境文件里。发布物用本地打包或 GitHub Release 的节点包（见 `scripts/build-node-tar.sh`）。

```bash
sudo mkdir -p /opt/redi-multi
sudo tar xzf redi-multi.tar.gz -C /opt/redi-multi
cd /opt/redi-multi/redi-multi
cp -n config.json.example config.json
```

`config.json` 里把 `server_ip` 改成这台机器的主公网 IPv4，`default_redirect_url` 改成未命中域名时的默认页。`addr` 保持 `0.0.0.0:80`，`tls_addr` 保持 `0.0.0.0:443`。

环境文件路径要和 systemd 单元一致。CentOS 7 的 `EnvironmentFile` 不要写 `export`：

```bash
cat > /opt/redi-multi/redi-multi/env.sh <<'EOF'
REDI_DB_HOST=主控IP
REDI_DB_PORT=3306
REDI_DB_USER=redi_node
REDI_DB_PASSWORD=与主控相同的库密码
REDI_DB_NAME=redi_admin
# 可选：REDI_DB_TLS=1 或 skip-verify（节点连库 TLS）
REDI_MASTER_URL=https://主控域名
# 仅 lab：主控尚无 HTTPS 时可 REDI_MASTER_INSECURE=1 且 http://（生产勿用）
REDI_NODE_TOKEN=后台创建节点时只显示一次的 Token
EOF
chmod 600 /opt/redi-multi/redi-multi/env.sh
```

`REDI_NODE_TOKEN` 来自后台「节点管理」新建节点后的提示。丢失就在该节点上轮换 Token，把新值写回 `env.sh` 并重启节点。

**lagran（必开）：** 面向被干扰域名，示例 `config.json` 与 `deploy-node.sh` 默认 **`lagran.enabled: true`**。节点出站 80/443 会经 NFQUEUE 调整 TCP 窗口；日志里不应出现 `[lagran] disabled`。仅本地无特权调试时可设 `REDI_LAGRAN_DISABLED=1` 或把 `enabled` 改为 false。

lagran 需要 root 或 `CAP_NET_ADMIN`（`deploy-node.sh` 会自动 `setcap`）：

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
mysql -h 主控IP -u redi_node -p redi_admin -e "CALL redi_node_bump_skip('__smoke__')"
```

节点或主控代码就绪、需要上线时，在开发机执行 `./scripts/build-node-tar.sh` / `./scripts/build-master-tar.sh`（产物默认在 `node/`、`master/` 下，已 gitignore，**勿提交**）。或通过 **`v*` tag** 触发 Release 工作流上传包。`build-node-tar.sh` 会拒绝仍然动态链接 libc 的二进制。节点行为（跳转、限速、Agent）均在 **二进制** 内，改 Go 源码后必须重新打包并部署到每台节点。

## 4. 上线顺序

1. 超级管理员打开「DNS 与节点池」，填写平台主域名。手动模式按页面参考表去 DNS 厂商添加 pool 的 A 和租户 CNAME。API 模式保存厂商凭证后点同步。
2. 「节点管理」添加节点。主 IP 填这台机器的主公网 IPv4，其他 IP 按行填在「其他公网 IP」。保存时复制 Token 到该机器的 `env.sh`。
3. 添加或改了 IP 之后，手动模式去厂商改 pool 的 A；API 模式再点一次同步。删除节点不会自动删 DNS 记录。API 同步失败时见 [docs/CONSOLE.md](docs/CONSOLE.md) §2「同步失败后的检查」（厂商侧可能已部分更新）。
4. **超级管理员** 为每个租户在「分组管理」建分组（须选择 **所属租户账号**）；租户在「域名管理」添加客户域名。把列表里的 CNAME 目标交给客户。
5. 预检显示「已指向」后，点「申请证书」。队列跑起来之后，状态会从验证中到已生效。

**证书 / ACME 检查清单**

- 至少一台节点 **启用** 且 **15 分钟内有心跳**；长期故障的节点请先禁用，避免阻塞（主控只需任一台在线节点同步 HTTP-01 挑战即可）。
- 多机跑 `queue:work` 时 `CACHE_DRIVER=redis`（共享 ACME 锁）。
- 节点 `REDI_MASTER_URL` 生产必须为 **https://**（lab 可 `REDI_MASTER_INSECURE=1` + `http://`）；Agent **0.1.3+** 与主控分项 revision 匹配。证书 `ack` 在无对应部署行时返回 **200**（`ignored: true`，幂等）。
- 默认已启用 `REDI_MIN_AGENT_VERSION=0.1.3`（全部节点 API）；旧节点须升级 `redi-multi` 或临时设 `REDI_MIN_AGENT_VERSION=` 禁用。
- `php artisan config:cache` 前请确认 `.env` 中 `REDI_ALLOW_TRUST_ALL_PROXIES`、`REDI_MIN_AGENT_VERSION` 等已写入（它们进入 `config/redi.php`，缓存后仍生效）。
- 续期/清理/health 由 `/etc/cron.d/redi-renew` 直调 `artisan`（**03:10 / 04:10 / 08:30**），**勿**配置 `php artisan schedule:run`。若有历史 Unicode 域名或非法 `skip_url` 可执行 `php artisan redi:normalize-domain-hosts --dry-run` 再正式跑（无效 skip 会被清空并提示补写）。
- 更新主控代码后执行 `php artisan migrate --force`（仅当仓库有新迁移）。节点库账号为 **`redi_node`** 时重跑 `sudo MYSQL_ROOT_PASSWORD='…' bash scripts/centos7/grant-node-mysql.sh <节点IP> <DB_PASS>`（仅 `EXECUTE` 该过程）。

客户自己要做的事见 [docs/CUSTOMER-CNAME-HTTPS.md](docs/CUSTOMER-CNAME-HTTPS.md)。

## 不要这样做

- 不要把客户域名 A 到某一台节点的 IP。
- 不要把同一台机器的每个 IP 各建一条节点。多出来的节点没有自己的进程，证书申请会一直等它上报。
- 不要 `iptables -F` 清空防火墙，也不要定时重启 MySQL。
- 不要把数据库密码写进仓库或 `mysqlconnect.go`。
