# CentOS 7.9 真机安装验收清单

**定位：** 在跟完 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) 之后，用本清单 **逐项打勾、查漏**；不是第二套安装教程。快速签收可用 [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)。

在 **两台（或以上）CentOS 7.9** 上，用 `deploy-master.sh` / `deploy-node.sh` 完成主控 + 节点后逐项核对。业务侧 HTTP-01、DNS、证书等见 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)（F1–F8）。

| 角色 | 示例 IP | 说明 |
|------|---------|------|
| 主控 | `1.2.3.4` | 跑 Laravel、MariaDB、队列 |
| 节点 1 | `5.6.7.8` | 跑 `redi-multi` |

下文命令中的 IP、密码、路径请换成你的。部署说明见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)，填空示例见 [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md)。

---

## 0. 环境前提（两台机器各执行）

- [ ] `cat /etc/centos-release` 为 **CentOS Linux release 7.9.2009**（或其他 `7.x`，脚本要求 `VERSION_ID` 以 `7` 开头）。
- [ ] 云厂商安全组/外层防火墙已放行：**22、80、443**；主控还需允许 **来自节点公网 IP 的 3306**（脚本用 firewalld 按 `NODE_IPS` 放行，外层仍要允许）。
- [ ] 主控与节点 **公网 IP** 与 `vars.env` 里填写的一致（节点访问库走主控公网 IP + `skip-name-resolve`）。
- [ ] 已安装 `git`，或已将 Release 包与 `scripts/centos7/`、`db/` 拷到机器（见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 发布包路径）。

```bash
cat /etc/centos-release
uname -r
curl -4 -sI -o /dev/null -w 'HTTP %{http_code}\n' https://vault.centos.org/ || echo "检查出口网络"
```

---

## 1. 主控：准备与一键安装

在 **主控** 上（仓库建议 `/root/301`，**不要**克隆到 `/opt/301`）：

```bash
cd /root
git clone https://github.com/freejbgo/301.git
cd 301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

`vars.env` 至少填写（节点 IP 已知则写入 `NODE_IPS`，空格分隔）：

```bash
MASTER_IP="1.2.3.4"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
NODE_IPS="5.6.7.8"
MASTER_INSTALL_ROOT="/opt/301"
MASTER_URL="http://1.2.3.4"
ADMIN_HTTPS="false"
```

一键安装：

```bash
sudo bash scripts/centos7/deploy-master.sh
```

- [ ] 脚本 **退出码为 0**，末尾提示后台地址与初始账号。
- [ ] 无报错停在 `yum`、MariaDB、Laravel migrate 等步骤。
- [ ] 存在存储过程 `redi_node_bump_skip`（`redi_admin.sql` 已含；脚本会检查；也可手验）：

```bash
mysql --defaults-extra-file=/root/.redi-mysql.cnf redi_admin -e \
  "SHOW PROCEDURE STATUS WHERE Db='redi_admin' AND Name='redi_node_bump_skip'"
```

---

## 2. 主控：系统与服务验收

在 **主控** 上执行：

```bash
systemctl is-active firewalld mariadb php-fpm nginx redi-queue
grep -q '\-\-max-time=3600' /etc/systemd/system/redi-queue.service
php -r 'echo PHP_VERSION, "\n";'
mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT COUNT(*) FROM redi_admin.admin_users;"
mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SHOW VARIABLES LIKE 'skip_name_resolve';" | grep -qi 'ON'
curl -sI -o /dev/null -w '%{http_code}\n' "http://127.0.0.1/admin"
cd /opt/301/master && sudo -u nginx php artisan redi:health
grep -E '^REDI_HEALTH_WEBHOOK_URL=.+' /opt/301/master/.env || echo '建议配置 REDI_HEALTH_WEBHOOK_URL（Cron 08:30 --notify）'
grep -E 'renew|prune|health' /etc/cron.d/redi-renew
grep -E 'cd /opt/301/master' /etc/cron.d/redi-renew
grep -q 'redi:prune-acme-challenges' /etc/cron.d/redi-renew
grep -q 'redi:health' /etc/cron.d/redi-renew
grep -q 'redi:ops-webhook' /etc/cron.d/redi-renew
grep -qF '--task=redi:renew-certificates' /etc/cron.d/redi-renew
grep -qF '--task=redi:prune-acme-challenges' /etc/cron.d/redi-renew
grep -qF '--task=redi:health' /etc/cron.d/redi-renew
! grep -q 'master/master' /etc/cron.d/redi-renew
! grep -q 'schedule:run' /etc/cron.d/redi-renew
ss -lntp | egrep ':80|:443|:3306' || true
sudo firewall-cmd --list-all
```

| 检查项 | 期望 |
|--------|------|
| `firewalld` / `mariadb` / `php-fpm` / `nginx` / `redi-queue` | 均为 `active`；`redi-queue` unit 含 `--max-time=3600` |
| `/etc/cron.d/redi-renew` | **03:10** `redi:renew-certificates`、**04:10** `redi:prune-acme-challenges`、**08:30** `redi:health --notify`；失败行尾 `|| redi:ops-webhook --task=…` 与对应 artisan 命令一致；`cd` 为 **`/opt/301/master`**（勿含 `master/master`）；勿另配 `schedule:run`（`Kernel` 无内置 schedule）。自定义 `MASTER_INSTALL_ROOT` 时把下面 `grep` 中的路径改成 `${MASTER_INSTALL_ROOT}/master` |
| 告警 Webhook | `.env` 已配置 `REDI_HEALTH_WEBHOOK_URL`（或 `REDI_OPS_WEBHOOK_URL` 作兜底）；`redi:health --notify` 在 webhook 发送成功时退出 **0** |
| PHP 版本 | **8.0.x**（`deploy-master` 仅接受 8.0.x） |
| `admin_users` | ≥ 1 |
| `skip_name_resolve` | **ON**（`deploy-master` 安装 `redi-skip-name-resolve.cnf`） |
| `curl` 后台 | **302** 或 **200** |
| 3306 | 监听；**不要**对 `0.0.0.0/0` 在安全组全网开放 |
| firewalld | 含 `ssh` `http` `https`；对节点 IP 有 mysql 相关 rich rule（若已配置 `NODE_IPS`） |
| 节点 DB 权限 | 远程账号 `redi_node` 仅 EXECUTE `redi_node_bump_skip`（`deploy-master` / `grant-nodes-from-vars` 按 `NODE_IPS` 同步，或单 IP `grant-node-mysql.sh`） |
| `redi:health` | 退出码 **0**（可有 **hints**，见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)）；生产须已关 `APP_DEBUG` 并配好 DNS 与节点池 |
| 证书部署失败重试 | 超管后台或 `php artisan redi:requeue-failed-deployments`（可选 `--node=` / `--cert=`）；仅重试**启用节点**上、证书仍可下发的 failed 行 |

Nginx 重复部署行为：

- [ ] `/etc/nginx/conf.d/301-master.conf` 含 `301-master-managed` 时，再跑 `deploy-master.sh` 会刷新 `root`。
- [ ] 若你删掉该标记并手工加 HTTPS，再跑脚本 **不应** 覆盖该文件（见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)）。

程序目录：

```bash
test -f /opt/301/master/artisan && test -f /opt/301/master/vendor/autoload.php
sudo -u nginx php /opt/301/master/artisan about 2>/dev/null | head -5
```

- [ ] `/opt/301/master` 为发布包解压结果，**不是** `master/master` 双层克隆目录。

---

## 3. 主控：后台与节点登记

浏览器打开 `http://1.2.3.4/admin`（或你的 `MASTER_URL`）：

- [ ] 使用 `admin` / `Redi301@Install2026` 登录成功。
- [ ] 仍为默认密码时被 **强制改密**；完成改密后可进入「节点管理」等菜单。

在「节点管理」：

- [ ] 新建节点，**主公网 IP** = `5.6.7.8`。
- [ ] 复制 **只显示一次** 的 Token，保存到节点 `vars.env` 的 `REDI_NODE_TOKEN`。

若主控部署时 `NODE_IPS` 为空，补写后同步授权（二选一）：

```bash
# 主控 vars.env 加上 NODE_IPS="5.6.7.8"
sudo bash scripts/centos7/deploy-master.sh
# 或仅同步 MySQL + firewalld，不重装主控：
sudo bash scripts/centos7/grant-nodes-from-vars.sh
```

- [ ] 主控脚本再次成功；节点 IP 已授权 MySQL（可用下面节点步骤验证连库）。

---

## 4. 节点：准备与一键安装

在 **节点 5.6.7.8** 上：

```bash
cd /root && git clone https://github.com/freejbgo/301.git && cd 301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

节点 `vars.env` 示例（与主控共享 `DB_PASS`；Token 填后台复制的值）：

```bash
MASTER_IP="1.2.3.4"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
NODE_IPS=""
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
MASTER_URL="http://1.2.3.4"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="5.6.7.8"
DEFAULT_REDIRECT_URL="https://www.example.com"
REDI_MASTER_INSECURE="1"
REDI_MASTER_URL="http://1.2.3.4"
REDI_NODE_TOKEN="后台复制的Token"
```

（`deploy-node.sh` 默认要求 `https://`；主控仍用 HTTP/IP 访问后台时，须设 `REDI_MASTER_INSECURE=1` 与 `http://` 成对使用。上主控 HTTPS 后改为 `https://` 并去掉 `REDI_MASTER_INSECURE`。）

```bash
sudo bash scripts/centos7/deploy-node.sh
```

- [ ] 脚本 **退出码为 0**，输出「数据库连接正常」。
- [ ] 若 Token 已填，提示已写入 Token。

---

## 5. 节点：系统与服务验收

在 **节点** 上：

```bash
systemctl is-active firewalld redi-multi
ss -lntp | egrep ':80|:443|:8080' || true
sudo firewall-cmd --list-all
/opt/redi-multi/redi-multi/bin/redi-multi -version 2>/dev/null || true
grep -E 'server_ip|"enabled"' /opt/redi-multi/redi-multi/config.json
getcap /opt/redi-multi/redi-multi/bin/redi-multi
journalctl -u redi-multi --no-pager -n 30 | grep -i lagran || true
mysql -h 1.2.3.4 -u redi_node -p'YourStrongDbPass_2026' redi_admin -e "CALL redi_node_bump_skip('__smoke__')"
curl -sI -o /dev/null -w '%{http_code}\n' "http://127.0.0.1/"
```

| 检查项 | 期望 |
|--------|------|
| `redi-multi` | `active` |
| 监听 | **80、443** 由进程占用；**8080** 仅本机/内网，**安全组不要对公网开放 8080** |
| firewalld | 仅 `ssh` `http` `https` |
| `config.json` `server_ip` | 等于 `5.6.7.8`（与后台节点 IP 一致，供对照；二进制不读） |
| `lagran.enabled` | **true**（被干扰域名场景默认） |
| `getcap` | 含 `cap_net_admin` |
| lagran 日志 | 无长期 `[lagran] disabled`（`deploy-node.sh` 也会校验） |
| MySQL 从节点连主控 | 成功 |
| 本机 HTTP | 有响应（可能 301/404，视配置而定） |

主控后台：

- [ ] 「节点管理」中该节点 **有心跳/在线**（稍等 5–15 秒刷新）。

---

## 6. 与业务验收的衔接（F1–F8）

完成本清单后，在后台按 [docs/CONSOLE.md](CONSOLE.md) 配置平台域名、池与租户域名，再执行 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) 的 **F1–F8**（DNS、跳转、证书、强制 HTTPS 等）。

快速冒烟（可选，需已添加测试域名并指向节点）：

- [ ] （**DNS API 自动**）若曾出现同步失败或 `dns_synced_at` 未前进：按 [CONSOLE.md](CONSOLE.md) §2 **「同步失败后的检查」** 核对厂商记录并重试同步；`redi:health --json` 可出现 hint `dns_api_tenants_unsynced`（退出码仍可为 0）。
- [ ] 客户域 CNAME 预检「已指向」。
- [ ] 单域「申请证书」后状态到「已生效」。
- [ ] `https://客户域/` 证书与跳转正常。

---

## 7. IPv4/IPv6 双栈（可选）

**前提：** 默认 **不** 发布 Pool AAAA；节点 `0.0.0.0` 监听在 Linux 上已双栈；lagran 对 IPv6 出站与 IPv4 相同。需要节点有 **公网 IPv6**、主控有 **IPv6 出口**。步骤与陷阱摘要见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §12、[NETWORKING-IPv6.md](NETWORKING-IPv6.md)。

在后台「节点管理」填写主 IPv6 / 其他 IPv6（**不要**写入 `vars.env` 的 `NODE_IPS`）。

### 7.1 发布开关关闭（默认）

- [ ] 主控 `.env` 未设置或 `REDI_PUBLISH_POOL_AAAA=false`，已执行 `sudo -u nginx php artisan config:cache`。
- [ ] 节点已登记 IPv6 后做 DNS 同步（API 模式或手动对照参考表）：厂商 **Pool A 与开关开启前一致**；**不出现新的 AAAA**（参考表也不增加 AAAA 行）。
- [ ] `NODE_IPS` 仍 **仅 IPv4**；`grant-nodes-from-vars` / 防火墙 3306 行为与 IPv4 节点一致。

### 7.2 打开 Pool AAAA 前（主控侧连通）

在 **主控**上对后台 **`enabledPublicIpv6s()` 里的每一个公网 IPv6**（含其他 IPv6）测 80（仅在节点本机 `curl` **不算**）。默认 `0.0.0.0` 监听在 Linux 上已能接 IPv6，**不要**为此改 `config.json`。

```bash
curl -6 -sS -o /dev/null -w '%{http_code}\n' --connect-timeout 5 "http://[2001:db8::1]/"
```

将 `2001:db8::1` 换成实际登记地址；多个 IPv6 时逐个执行。

若节点 **HTTPS 未关闭**（未写 `tls_addr` 等价于开启；仅 JSON `null` 或 `""` 为关闭），对每个地址再测 443：

```bash
curl -6 -k -sS -o /dev/null -w '%{http_code}\n' --connect-timeout 5 "https://[2001:db8::1]/"
```

- [ ] 每个登记 IPv6 的 80 均得到非 `000` 的 HTTP 状态码。
- [ ] HTTPS 开启时，每个登记 IPv6 的 443 均得到非 `000` 的状态码。
- [ ] 主控无 IPv6 出口时 **不要** 进行 §7.3。

### 7.3 发布开关打开

- [ ] `/opt/301/master/.env` 设置 `REDI_PUBLISH_POOL_AAAA=true`，`config:cache` 后 DNS 同步：厂商 Pool 出现与启用节点一致的 **AAAA**（A 记录仍正常）。
- [ ] 将节点 IPv6 清空并保持 `REDI_PUBLISH_POOL_AAAA=true`，再同步：**AAAA 被删或对齐为空**，**A 仍在**。
- [ ] （可选）`REDI_DNS_PRECHECK_ALLOW_AAAA_TO_POOL=true` 时，客户域仅 AAAA 指向 Pool 的预检行为符合预期；默认 false 时仅 AAAA 不应单独通过预检。

### 7.4 访客跳转与 lagran（打开发布开关后）

- [ ] 分别用 **IPv4** 和 **IPv6** 访问客户域名，跳转均成立（与 IPv4 行为一致）。
- [ ] 节点上 `iptables -L OUTPUT` 与 `ip6tables -L OUTPUT` 均可见 lagran 的 NFQUEUE 规则，**队列号相同**（如 `1000:1127`），不是另一段号码。

---

## 8. 常见问题速查

| 现象 | 处理 |
|------|------|
| 节点 `deploy-node.sh` 连不上 MySQL | 主控 `NODE_IPS` 是否含节点公网 IP；主控是否 `deploy-master.sh` 或 `grant-nodes-from-vars.sh`；云安全组 3306 |
| 下线节点后仍能从公网连主控 3306 | 从 `NODE_IPS` 去掉该 IP（或清空列表）后重跑 `deploy-master.sh` 或 `grant-nodes-from-vars.sh` |
| 节点无心跳 | `REDI_MASTER_URL` 是否可达；Token 是否正确；`systemctl status redi-multi` 日志 |
| 后台 502/空白 | `systemctl status php-fpm nginx`；`/opt/301/master/storage` 权限属 `nginx` |
| 重复跑主控脚本后 HTTPS 没了 | 是否曾去掉 `301-master-managed`；HTTPS 建议单独 `conf.d` 文件 |
| `yum` 很慢或 IPv6 失败 | 脚本已尝试 `ip_resolve=4`；检查机器 IPv4 出口 |
| 已开 `REDI_PUBLISH_POOL_AAAA` 后 HTTP-01 / 签发超时 | 主控探测客户域可能走 IPv6；在主控对每个节点公网 IPv6 做 `curl -6` 通 80，见 §7 与 DEPLOY-centos7 §12 |

---

## 9. 开发机容器冒烟（非真机替代）

仅验证包与 yum/migrate，**不能**代替本节 systemd/firewalld 验收：

```bash
sudo bash scripts/centos7/run-on-centos7-host.sh
```

发版前在开发机还可跑 `bash scripts/dev-preflight.sh`（Go/PHPUnit/脚本校验，见 [DEVELOPMENT.md](DEVELOPMENT.md)）。

见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §7。

---

**签收**：主控 §1–3、节点 §4–5 全部打勾后，可认为 **CentOS 7.9 脚本化部署验收通过**；业务 F 项以 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) 为准。有公网 IPv6 环境时，可另做 §7 双栈打勾（非脚本安装必需）。
