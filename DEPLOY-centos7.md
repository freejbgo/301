# CentOS 7 部署

**推荐系统：CentOS 7.9**（`7.9.2009` 等 7.x 小版本均可）。内核小版本可以不同，脚本不按内核编译，也不编译内核模块。部署脚本会校验 `VERSION_ID` 为 `7` 开头。

| 机器 | 脚本 | 装好之后 |
|------|------|----------|
| 主控 | `scripts/centos7/deploy-master.sh` | 程序在 `/opt/301/master`，网站根目录 `/opt/301/master/public` |
| 每台节点 | `scripts/centos7/deploy-node.sh` | 程序在 `/opt/redi-multi/redi-multi` |

开发阶段 [GitHub Releases](https://github.com/freejbgo/301/releases) 可能为空；需要部署时在仓库根目录执行 `scripts/build-master-tar.sh` / `scripts/build-node-tar.sh`，或等你发 **`v*` tag** 后从 Releases 下载（打 tag 会触发 `.github/workflows/release.yml`：Go 测试、PHPUnit **8.4 + 8.0**、`composer audit`、CentOS 7 脚本校验与容器冒烟通过后，才构建 tar 并上传；发布树上还会再跑 audit + PHPUnit）。也可在 `vars.env` 里设置 `REDI_MASTER_TAR` / `REDI_NODE_TAR` 指向打包结果的绝对路径：

- 主控包：本地 `scripts/build-master-tar.sh` → `master/master.tar.gz`（含 `vendor`，无 `.env`），或 GitHub Release 的 `master.tar.gz`。
- 节点包：本地 `scripts/build-node-tar.sh` → `node/redi-multi.tar.gz`（CentOS 7 静态 `bin/redi-multi`），或 Release 的 `redi-multi.tar.gz`。
- 仓库 **不跟踪** 上述 tar；`deploy-*.sh` 默认找同路径下的文件，也可用 `vars.env` 的 `REDI_MASTER_TAR` / `REDI_NODE_TAR` 指向 `/tmp` 等。

源码在仓库的 `master/master/`。那是给开发和重新打包用的。自动安装不要把仓库克隆到 `/opt/301`，否则会和发布包解压出来的 `/opt/301/master` 叠在一起。

填好 IP 的示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。**真机 CentOS 7.9 逐步验收**见 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md)。**主控已在跑、只跟上新发布包**见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md)。后台操作见 [docs/CONSOLE.md](docs/CONSOLE.md)。

## 开发机 / 发版前（与 CI 一致）

在本地打 `v*` 标签或上传 tar 到服务器之前，建议在仓库根目录执行 **`bash scripts/dev-preflight.sh`**（或按 [docs/AUDIT-CENTOS7-PRODUCTION.md](docs/AUDIT-CENTOS7-PRODUCTION.md) **§9** 分步跑）。与 GitHub `ci.yml` / `release.yml` 核心门禁相同；本机无 Docker 时 DB 校验脚本会跳过，有 Docker 时才会真正起 MariaDB 容器。

## 1. 准备变量

在要部署的机器上先有这份仓库（`git clone` 到 `/root/301` 这类目录即可）。

```bash
cd /root/301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

密码、Token 和 URL 不要包含空格、引号、反引号、`$`、`#` 或反斜杠。`vars.env` 不要提交。

主控至少要填 `MASTER_IP`、`DB_PASS`、`MYSQL_ROOT_PASS`、`MASTER_URL`、`ADMIN_HTTPS`。还没有证书、只用 IP 访问时：`MASTER_URL="http://主控IP"`，`ADMIN_HTTPS="false"`。已知节点 IP 就写进 `NODE_IPS`，空格分隔。

节点还要填本机的 `NODE_PUBLIC_IP`、`DEFAULT_REDIRECT_URL`、`REDI_MASTER_URL`（生产须 **`https://`**；主控尚未配证书、仅用 IP 的 HTTP 联调时，可设 `REDI_MASTER_INSECURE=1` 并把 `REDI_MASTER_URL` 写成 `http://主控IP`，见 `vars.example.env`）。可选 `REDI_DB_TLS`（`1`/`true` 或 `skip-verify`）写入节点 `env.sh`。Token 等主控后台创建节点后再填 `REDI_NODE_TOKEN`。

## 2. 部署主控

```bash
sudo bash scripts/centos7/deploy-master.sh
```

脚本会：

1. 把已经失效的 CentOS 7 源换成 `vault.centos.org`，EPEL 换成 Fedora 归档。
2. 安装 Remi 的 PHP 8.0、Nginx、firewalld。不安装 PHP 8.1 或更高版本。
3. 安装 MariaDB 10.6。CentOS 7 自带的 5.5 导不进这份 `utf8mb4` 库。
4. 设置 root 密码，关闭反向域名解析，数据库监听 IPv4。3306 不对全网开放，只放行 `NODE_IPS`。
5. 库是空的才导入 `db/redi_admin.sql`（菜单 + 业务表 + 默认 `admin` + 节点存储过程 **`redi_node_bump_skip`**；`migrations` 登记至 `100009`）。已经有 `admin_users` 时不会覆盖。
6. 解压 `master/master.tar.gz` 到 `/opt/301/master`，写入 `.env`，执行 `key:generate`、**`migrate --force`**（绿场常无新迁移；发版后有新迁移时改库）、`storage:link`，并 **检查存储过程是否存在**（应由上一步 SQL 导入；不存在则脚本失败退出）。
7. 配置 PHP-FPM（用户 `nginx`，sock `/var/run/php-fpm/www.sock`）和 Nginx。开启 SELinux 时会放行网站访问网络和数据库。
8. 安装队列 `redi-queue`：`queue:work --sleep=3 --timeout=320 --tries=100`。`/etc/cron.d/redi-renew` 每天 **03:10** 续期证书、**04:10** 清理 ACME、**08:30** `redi:health --notify`（webhook 发送成功或无 issues 时 **退出 0**；未配置 URL 且有 issues 或 artisan 异常时 `|| redi:ops-webhook`）；直接 `php artisan …`，**勿**配置 `php artisan schedule:run`（续期/清理/health 仅由本文件触发；`Kernel` 内已无 schedule 任务）。多台机器跑 `queue:work` 时主控 `.env` 须 `CACHE_DRIVER=redis`（ACME 全局锁）。
9. 防火墙开放 22、80、443。

重复执行不会重置已有 `.env`、`APP_KEY` 和业务数据。`/etc/nginx/conf.d/301-master.conf` 若**没有** `301-master-managed` 标记则不会覆盖（便于你在同一文件里手工加 HTTPS）；带该标记或由脚本生成的文件会在重复部署时刷新 `root` 路径。HTTPS 也可单独增加 `conf.d` 片段。

打开 `http://主控IP/admin`。用户名 `admin`，密码 `Redi301@Install2026`。若仍为默认密码，系统会 **强制进入改密页**（`Admin → 用户设置`），改完后才能使用其它菜单。绿场数据库说明见 [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md)。

有域名之后：把 `vars.env` 的 `MASTER_URL` 改成 `https://主控域名`、`ADMIN_HTTPS` 改成 `true`，再改服务器上的 `/opt/301/master/.env`（已有 `.env` 时脚本不会重写它），给 Nginx 配上证书，然后 `systemctl restart php-fpm redi-queue`。这张证书只保护后台。

## 3. 部署节点

先在主控后台「节点管理」新建一条节点，主公网 IP 填这台机器的 IPv4，复制只显示一次的 Token。主控的 `NODE_IPS` 里也要有这个 IP；如果部署主控时还没写，补上后在主控再执行一次 `deploy-master.sh`，它会补授权和防火墙。

把同一份仓库放到节点上（或只把 `redi-multi.tar.gz`、`deploy-node.sh`、`vars.env` 拷过去）。`vars.env` 里填好 `REDI_NODE_TOKEN`。

```bash
sudo bash scripts/centos7/deploy-node.sh
```

脚本会安装 firewalld、iptables、`setcap` 和 MySQL 客户端，尝试加载 `nfnetlink_queue`（编进内核时加载失败可以忽略），解压发布包（**不含**打包进 tar 的本机 `config.json`，从 `config.json.example` 生成），写 `env.sh`（没有 `export`），更新 `config.json` 的 `server_ip` / `default_redirect_url`、确保 **lagran 开启**，执行 `setcap cap_net_admin+ep`，启动后在校验 **cap_net_admin** 与 lagran 日志。防火墙只开放 22、80、443，不开放 8080。

Token 空着也可以把进程跑起来，但 Agent 不会向主控注册，证书同步也不会做。补上 Token 后再执行一次脚本。

同一台机器的其他公网 IP 填在后台该节点的「其他公网 IP」，不要再部署第二个进程。

自测连库失败时，脚本会停下来。先确认主控已经对这台机器的出口 IP 授权。

## 4. 后台里接着做

1. 「DNS 与节点池」填写平台主域名。
2. 节点状态正常后，手动模式去 DNS 厂商按参考表添加 pool 的 A 和租户 CNAME。API 模式点同步（厂商记录 **先增/改、后删**；任一步失败则 **不更新** `dns_synced_at`，可修正后重试；详见 [SECURITY.md](SECURITY.md)）。
3. **超级管理员** 在「分组管理」建分组（须选 **所属租户账号**）；租户在「域名管理」添加客户域名，把 CNAME 目标交给客户。客户 CNAME 到这个目标，不要把域名 A 到某一台节点。
4. 预检为「已指向」后点「申请证书」。

## 5. 以后加一台节点

在主控的 `vars.env` 里把新 IP 加进 `NODE_IPS`，执行 `sudo bash scripts/centos7/deploy-master.sh`。然后在新机器上执行 `deploy-node.sh`。后台新建节点，把 Token 写进新机器的 `vars.env` 再执行一次节点脚本。pool 的 A 记录要包含新 IP。删除节点不会自动删 DNS 记录。

**从 `NODE_IPS` 里去掉某个 IP 后**，再跑一次 `deploy-master.sh` 会撤销该 IP 的 MySQL 远程账号和 firewalld 3306 规则（状态记在 `/var/lib/redi/authorized_node_ips`）。节点库账号 **`redi_node`** 仅可 **EXECUTE** `redi_node_bump_skip`（按 Host 跳转计数），不能 SELECT 域名全表或访问证书等其它表（见 `grant-node-mysql.sh`）。

## 6. 重新打包

改了主控依赖或节点源码后，在开发机执行：

```bash
./scripts/build-master-tar.sh
./scripts/build-node-tar.sh
```

节点包内含静态 `bin/redi-multi`（含 80/443 上 `/c.js`、`/api.js` 限速等逻辑）。只更新主控 PHP 时重跑 `deploy-master.sh`；改过 `node/redi-multi` 后必须重新打包并在各节点 `deploy-node.sh` 或至少替换二进制并重启 `redi-multi`。

在需要部署时本地打包（或打 tag 发 Release），勿把 tar 提交进 git。节点打包会拒绝仍然动态链接 libc 的二进制。

## 7. 开发机用 CentOS 7 容器做冒烟（可选）

在**非 CentOS 的开发机**上可用 podman/docker 拉起 **CentOS 7.9.2009** 镜像，校验发布包、yum 源、MariaDB 与 `php artisan migrate`（不替代真实 7.9 虚拟机上的 systemd / firewalld 验收）：

```bash
# 安装 podman 后执行（包管理器因宿主机而异）
sudo bash scripts/centos7/run-on-centos7-host.sh
```

脚本使用 `--network host`，避免部分环境下 podman 桥接网无外网导致 `yum` 失败。日志在容器内 `/tmp/redi-c7-integration.log`。完整 `deploy-master.sh` / `deploy-node.sh` 仍须在真实 CentOS 7.9 上按 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md) 验收。

## 8. 节点 lagran 运维（可选）

被干扰域名场景默认开启 lagran（需 `cap_net_admin` 与 `nfnetlink_queue`）。节点上：

```bash
bash scripts/centos7/lagran-diagnose.sh /opt/redi-multi/redi-multi
# 进程异常退出后若 OUTPUT 链残留 NFQUEUE 规则：
sudo systemctl stop redi-multi
sudo bash scripts/centos7/lagran-cleanup-iptables.sh /opt/redi-multi/redi-multi
sudo systemctl start redi-multi
```

`lagran-cleanup-iptables.sh` 通过 `scripts/centos7/lagran_ports.py` 合并 `addr` / `tls_addr` 与 `lagran.port`，与节点进程写入 iptables 的 sport 一致。

## 9. 节点连主控 MySQL 的 TLS（可选）

节点 `env.sh` 支持 `REDI_DB_TLS`（见 [node/README.md](node/README.md)）：

| 值 | 含义 |
|----|------|
| `1` 或 `true` | 启用 TLS 并校验服务端证书 |
| `skip-verify` | 加密但不校验（内网自签） |

在 `scripts/centos7/vars.env` 设置 `REDI_DB_TLS="skip-verify"` 或 `REDI_DB_TLS="1"` 后重跑 `deploy-node.sh` 即可写入 `env.sh`。主控 MariaDB 侧需自行配置 SSL 监听与证书；跨公网连库 **强烈建议** TLS + 安全组仅放行节点 IP。

## 10. 多机 / 多 worker 队列（可选）

默认 `QUEUE_CONNECTION=database` + `CACHE_DRIVER=file` 适合 **单台主控、单个 `redi-queue`**。若要多进程或多台跑 `queue:work`：

1. 安装 Redis，`CACHE_DRIVER=redis`、`REDIS_*` 写入主控 `.env`
2. `php artisan config:cache`
3. 每台机器各跑 `queue:work`（或 systemd 多实例）

详见 [docs/OPS-MULTI-WORKER-QUEUE.md](docs/OPS-MULTI-WORKER-QUEUE.md)。`php artisan redi:health --json` 的 `hints` 会在 file 缓存 + 非 sync 队列时提示改 redis。

## 11. 主控安装后快速验收

默认程序目录为 `/opt/301/master`（`vars.env` 的 `MASTER_INSTALL_ROOT=/opt/301`）。完整清单见 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md)。

```bash
systemctl is-active nginx php-fpm mariadb redi-queue
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
cd /opt/301/master && sudo -u nginx php artisan redi:health --json
```

已上线主控若 Cron 仍是旧写法，可 `sudo bash scripts/patch-master-cron-ops-webhook.sh`（见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md) §4）。
