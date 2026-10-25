# CentOS 7 部署

**推荐系统：CentOS 7.9**（`7.9.2009` 等 7.x 小版本均可）。内核小版本可以不同，脚本不按内核编译，也不编译内核模块。部署脚本会校验 `VERSION_ID` 为 `7` 开头。

**生产路径是裸机 CentOS 7.x**：主控/节点用 `yum` + `systemd` + `deploy-*.sh` 即可，**不需要 Docker / Podman**。文档里的容器冒烟（§7）与 `dev-preflight` 里的 Docker 库校验仅给开发机或 CI 选用；你在 7.9 真机上按本文部署时不用装容器。

| 机器 | 脚本 | 装好之后 |
|------|------|----------|
| 主控 | `scripts/centos7/deploy-master.sh` | 程序在 `/opt/301/master`，网站根目录 `/opt/301/master/public` |
| 每台节点 | `scripts/centos7/deploy-node.sh` | 程序在 `/opt/redi-multi/redi-multi` |

**发布包（推荐）：** 开发机 `bash scripts/dev-preflight.sh` 后 `bash scripts/build-release-tarballs.sh`，`scp` 双 tar 到服务器，在 `vars.env` 设 `REDI_MASTER_TAR` / `REDI_NODE_TAR`（如 `/tmp/master.tar.gz`、`/tmp/redi-multi.tar.gz`）。步骤见 [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md)。可选：对 `main` 打 **`v*` tag** 从 [GitHub Releases](https://github.com/freejbgo/301/releases) 下载（Actions 配额不足时用本地 tar 即可）。

- 主控包：`master/master.tar.gz`（含 `vendor`，无 `.env`）。
- 节点包：`node/redi-multi.tar.gz`（CentOS 7 静态 `bin/redi-multi`）。
- 仓库 **不跟踪** tar。`deploy-*.sh` 默认找仓库内上述路径或 `/tmp`；**生产机应上传 tar 并写 `REDI_*_TAR`**，勿在机房依赖 composer/Go 自动打包。

源码在仓库的 `master/master/`。那是给开发和重新打包用的。自动安装不要把仓库克隆到 `/opt/301`，否则会和发布包解压出来的 `/opt/301/master` 叠在一起。

填好 IP 的示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。**真机 CentOS 7.9 逐步验收**见 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md)。**边缘池 F1–F8**见 [docs/ACCEPTANCE-EDGE-POOL.md](docs/ACCEPTANCE-EDGE-POOL.md)。**主控已在跑、只跟上新发布包**见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md)。后台操作见 [docs/CONSOLE.md](docs/CONSOLE.md)（DNS API 同步失败见 §2「同步失败后的检查」）。

## 开发机 / 发版前（与 CI 一致）

在**有 composer + Go 的发版机**（可以是 CentOS 7.9，也可以是别的 Linux）上打 tar 前，建议执行 **`bash scripts/dev-preflight.sh`**（或按 [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) 分步跑）。与 GitHub `ci.yml` / `release.yml` 核心门禁相同；**无 Docker 时照常可用**（仅跳过临时 MariaDB 容器校验，不影响在 7.9 真机上 `deploy-master` / `deploy-node`）。`verify-*` 索引见 [scripts/README.md](scripts/README.md)；本文与 `deploy-*.sh` / `redi_admin.sql` 的对齐由 **`bash scripts/verify-deploy-centos7-doc.sh`** 静态校验。

## 1. 准备变量

在要部署的机器上先有这份仓库（`git clone` 到 `/root/301` 这类目录即可）。

```bash
cd /root/301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

密码、Token 和 URL 不要包含空格、引号、反引号、`$`、`#` 或反斜杠（`vars.env` 由 shell 解析）。节点 `REDI_DB_PASSWORD` 经 MySQL 驱动连接器写入，可含 `@`、`:` 等 DSN 字符，但仍应避免上述 shell 麻烦字符。`vars.env` 不要提交。

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
5. 库是空的才导入 `db/redi_admin.sql`（菜单 + 业务表 + 默认 `admin` + 节点存储过程 **`redi_node_bump_skip`**，含节点 IPv6 列；`migrations` 表已与当前发布包对齐）。已经有 `admin_users` 时不会覆盖。
6. 解压 `master/master.tar.gz` 到 `/opt/301/master`，写入 `.env`，执行 `key:generate`、**`migrate --force`**（绿场导入 SQL 后一般为无待执行迁移，**不必手工 migrate**）、`storage:link`，并 **检查存储过程是否存在**（应由上一步 SQL 导入；不存在则脚本失败退出）。跟新发布包时的库变更见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md)。
7. 配置 PHP-FPM（用户 `nginx`，sock `/var/run/php-fpm/www.sock`）和 Nginx。开启 SELinux 时会放行网站访问网络和数据库。
8. 安装队列 `redi-queue`：`queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600`（worker 每小时由 systemd 重启，避免长期内存增长）。`/etc/cron.d/redi-renew` 每天 **03:10** 续期证书、**04:10** 清理 ACME、**08:30** `redi:health --notify`（webhook 发送成功或无 issues 时 **退出 0**；未配置 URL 且有 issues 或 artisan 异常时 `|| redi:ops-webhook`）；直接 `php artisan …`，**勿**配置 `php artisan schedule:run`（续期/清理/health 仅由本文件触发；`Kernel` 内已无 schedule 任务）。多台机器跑 `queue:work` 时主控 `.env` 须 `CACHE_DRIVER=redis`（ACME 全局锁）。
9. 防火墙开放 22、80、443。

重复执行不会重置已有 `.env`、`APP_KEY` 和业务数据。`/etc/nginx/conf.d/301-master.conf` 若**没有** `301-master-managed` 标记则不会覆盖（便于你在同一文件里手工加 HTTPS）；带该标记或由脚本生成的文件会在重复部署时刷新 `root` 路径。HTTPS 也可单独增加 `conf.d` 片段。

打开 `http://主控IP/admin`。用户名 `admin`，密码 `Redi301@Install2026`。若仍为默认密码，系统会 **强制进入改密页**（`Admin → 用户设置`），改完后才能使用其它菜单。绿场数据库说明见 [docs/GREENFIELD-INSTALL.md](docs/GREENFIELD-INSTALL.md)。

有域名之后：把 `vars.env` 的 `MASTER_URL` 改成 `https://主控域名`、`ADMIN_HTTPS` 改成 `true`，再改服务器上的 `/opt/301/master/.env`（已有 `.env` 时脚本不会重写它），给 Nginx 配上证书，然后 `systemctl restart php-fpm redi-queue`。这张证书只保护后台。

## 3. 部署节点

先在主控后台「节点管理」新建一条节点，主公网 IP 填这台机器的 IPv4，复制只显示一次的 Token。主控的 `NODE_IPS` 里也要有这个 IP；如果部署主控时还没写，补上后在主控再执行一次 `deploy-master.sh`，它会补授权和防火墙。

把同一份仓库放到节点上（或只把 `redi-multi.tar.gz`、`deploy-node.sh`、`vars.env` 拷过去）。`vars.env` 里填好 `NODE_INSTALL_ROOT`（默认 `/opt/redi-multi`）、`REDI_NODE_TOKEN` 等节点项。

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

在主控的 `vars.env` 里把新 IP 加进 `NODE_IPS`，执行 `sudo bash scripts/centos7/deploy-master.sh`（或仅同步授权/防火墙：`sudo bash scripts/centos7/grant-nodes-from-vars.sh`）。然后在新机器上执行 `deploy-node.sh`。后台新建节点，把 Token 写进新机器的 `vars.env` 再执行一次节点脚本。pool 的 A 记录要包含新 IP。删除节点不会自动删 DNS 记录。

**从 `NODE_IPS` 里去掉某个 IP 后**（或清空 `NODE_IPS` 撤销全部已记录节点），再跑一次 `deploy-master.sh` 或 `grant-nodes-from-vars.sh` 会撤销对应 IP 的 MySQL 远程账号和 firewalld 3306 规则（状态记在 `/var/lib/redi/authorized_node_ips`）。手工撤销单 IP：`sudo MYSQL_ROOT_PASSWORD='…' bash scripts/centos7/revoke-node-mysql.sh <IP>` 与 `sudo bash scripts/centos7/firewalld-revoke-mysql-from-node.sh <IP>`。新增单 IP 而不重装主控：`sudo MYSQL_ROOT_PASSWORD='…' bash scripts/centos7/grant-node-mysql.sh <IP> <DB_PASS>` 并 `sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh <IP>`。节点库账号 **`redi_node`** 仅可 **EXECUTE** `redi_node_bump_skip`。

## 6. 重新打包

改了主控依赖或节点源码后，在**发版机**（不必是 Docker；常见为带 composer/Go 的 Linux，或单独一台非生产的 7.9）执行：

```bash
bash scripts/dev-preflight.sh
bash scripts/build-release-tarballs.sh
```

再 `scp` 到各台 **CentOS 7.9 生产机** 并设置 `REDI_*_TAR`（见 [docs/RELEASE-PACKAGES.md](docs/RELEASE-PACKAGES.md)）。也可单独 `./scripts/build-master-tar.sh` / `./scripts/build-node-tar.sh`。

节点包内含静态 `bin/redi-multi`（含 80/443 上 `/c.js`、`/api.js` 限速等逻辑）。只更新主控 PHP 时重跑 `deploy-master.sh`；改过 `node/redi-multi` 后必须重新打包并在各节点 `deploy-node.sh` 或至少替换二进制并重启 `redi-multi`。

在需要部署时本地打包（或打 tag 发 Release），勿把 tar 提交进 git。节点打包会拒绝仍然动态链接 libc 的二进制。

## 7. 开发机用 CentOS 7 容器做冒烟（可选，生产不用）

若你**已经在 CentOS 7.9 真机**上部署，可跳过本节。仅在**非 CentOS 的开发机**上、且已安装 podman/docker 时，可拉起 **CentOS 7.9.2009** 镜像做辅助校验（发布包、yum 源、MariaDB、`php artisan migrate`）；**不能替代**真机上的 systemd / firewalld 验收：

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

`lagran-cleanup-iptables.sh` 会 `source` 节点 `env.sh`（若存在），并通过 `lagran_ports.py` 合并 `addr` / `tls_addr` / `lagran.port`，且读取 `REDI_HTTP_LISTEN`、`REDI_TLS_LISTEN`，与节点进程 `config.Init()` 后的 lagran sport 一致。

## 9. 节点连主控 MySQL 的 TLS（可选）

节点 `env.sh` 支持 `REDI_DB_TLS`（见 [node/README.md](node/README.md)）：

| 值 | 含义 |
|----|------|
| `1` 或 `true` | 启用 TLS 并校验服务端证书 |
| `skip-verify` | 加密但不校验（内网自签） |

在 `scripts/centos7/vars.env` 设置 `REDI_DB_TLS="skip-verify"` 或 `REDI_DB_TLS="1"` 后重跑 `deploy-node.sh` 即可写入 `env.sh`。主控 MariaDB 侧需自行配置 SSL 监听与证书；跨公网连库 **强烈建议** TLS + 安全组仅放行节点 IP。

节点 Agent 其它可选键（**不**由 `deploy-node.sh` 从 `vars.env` 写入，升级后按需编辑 `${NODE_INSTALL_ROOT}/redi-multi/env.sh` 并 `systemctl restart redi-multi`）：

| 键 | 含义 |
|----|------|
| `REDI_AGENT_EMPTY_CERT_CLEAR_AFTER` | 主控连续返回空证书列表多少次后才清空本机 TLS 内存（默认 **3**；`1` = 立即清空，与旧版相同） |

详见 [node/redi-multi/env.example](node/redi-multi/env.example) 与 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md) §3。

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
grep -q '\-\-max-time=3600' /etc/systemd/system/redi-queue.service
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
cd /opt/301/master && sudo -u nginx php artisan redi:health --json
# 可选：jq '.summary.health_queue_pending_threshold,.summary.health_queue_stale_hours,.summary.health_validating_stuck_hours,.summary.health_renew_validating_stuck_hours,.issues'
# 生产建议在 .env 配置 REDI_HEALTH_WEBHOOK_URL，并验证：sudo -u nginx php artisan redi:health --notify; echo exit=$?
# 证书部署 failed 行重试：后台或 php artisan redi:requeue-failed-deployments（见 PRODUCTION-READINESS.md）
```

已上线主控若 Cron 仍是旧写法，可 `sudo bash scripts/patch-master-cron-ops-webhook.sh`（见 [docs/UPGRADE-IN-PLACE.md](docs/UPGRADE-IN-PLACE.md) §4）。

## 12. 节点 IPv6 与 Pool AAAA（可选）

节点公网 IPv6 在后台「节点管理」填写（主 IPv6 / 其他 IPv6）。**不要**把 IPv6 写进 `vars.env` 的 `NODE_IPS`（该列表仍只用于 IPv4 的 MySQL 授权与 firewalld 3306）。默认不会在 DNS 厂商发布 Pool 的 AAAA；主控 `.env` 里 `REDI_PUBLISH_POOL_AAAA` 仍为 **false**（见 `master/master/.env.example`）。

### 何时打开发布开关

1. 节点网卡上已有 **公网 IPv6**，且 firewalld 已放行 http/https（`deploy-node.sh` 默认会）。发布包默认仍是 `0.0.0.0:80` / `0.0.0.0:443`；在 Linux 上 `redi-multi` 已经双栈接听，**不要**为了接 IPv6 去改 `config.json` 监听字符串。若只发布了 AAAA 而登记地址在主控上 `curl -6` 不通，Let's Encrypt 的 **HTTP-01 会优先走 AAAA**，签发与续期会超时。
2. 在 **主控机器**上，对后台 **`enabledPublicIpv6s()` 列出的每一个 IPv6**（含「其他 IPv6」，不是只测主地址）测 80（HTTP-01 从主控发出，只在节点本机 `curl` **不算**）：

   ```bash
   curl -6 -sS -o /dev/null -w '%{http_code}\n' --connect-timeout 5 "http://[2001:db8::1]/"
   ```

   将 `2001:db8::1` 换成实际登记地址（方括号是 URL 里的 IPv6 字面量写法）。每个地址都应得到非 `000` 的 HTTP 状态码。

   若节点 **HTTPS 未关闭**（`config.json` 未写 `tls_addr` 时等价于 `0.0.0.0:443` 已开启；仅 JSON `null` 或 `""` 表示关闭 HTTPS），对每个地址再测 443：

   ```bash
   curl -6 -k -sS -o /dev/null -w '%{http_code}\n' --connect-timeout 5 "https://[2001:db8::1]/"
   ```

3. 确认主控自身有 **IPv6 出口**（能执行上述 `curl -6`）。主控没有 IPv6 出网时 **不要** 设置 `REDI_PUBLISH_POOL_AAAA=true`。

4. 在 `/opt/301/master/.env` 设置 `REDI_PUBLISH_POOL_AAAA=true`，执行 `sudo -u nginx php artisan config:cache`，再在后台做 DNS 同步（或 API 模式点同步）。需要预检允许客户域名 AAAA 指向 Pool 时，另见 `REDI_DNS_PRECHECK_ALLOW_AAAA_TO_POOL`（默认 false，见 `.env.example`）。

关闭发布：把开关改回 `false` 并 `config:cache` **不会**自动删除厂商里已有 AAAA；要保持 `true` 且清空节点 IPv6 后再同步，才会按对账删掉 AAAA。A 记录行为与 IPv4 一致。

### 监听与配置（一般不用改）

- 发布包默认仍是 `0.0.0.0:80` / `0.0.0.0:443`。**不要**在本仓库的部署脚本或默认 `config.json` 里改监听。
- 只有把 `addr` 写成**某一个具体 IPv4**（不是 `0.0.0.0`）时，才会真的只听 IPv4；改回 `0.0.0.0` **不会**关掉 IPv6。
- 若仍要手改通配监听，写成 **`[::]:80`** / **`[::]:443`**（带方括号），**不要**写成 `::80` / `::443`（lagran 端口会解析错）。
- **不要**同时绑定 `0.0.0.0:80` 与 `[::]:80`（443 同理）。
- **`api_listen` 保持 `127.0.0.1:8080`**，不要改成 `[::]` 或 `0.0.0.0`。

### lagran 与防火墙

lagran 在 **OUTPUT** 上按 TCP **源端口**（与 `addr` / `tls_addr` 合并后的 `lagran.port`）匹配，**不是**按节点公网地址。`iptables` 与 `ip6tables` 使用**同一组** NFQUEUE 队列号（如 `1000:1127` …）；IPv6 访客的出站 TCP 与 IPv4 走同样的窗口处理。自检：`bash scripts/centos7/lagran-diagnose.sh`；kill -9 遗留规则：`sudo bash scripts/centos7/lagran-cleanup-iptables.sh`。

开放 80/443 时继续用部署脚本已有的服务放行即可；**不要**为「只匹配 IPv4」单独加 firewalld rich rule 而把 IPv6 挡在池外（否则 `curl -6` 与 HTTP-01 会失败）。

更完整的产品约定见 [docs/NETWORKING-IPv6.md](docs/NETWORKING-IPv6.md)。
