# CentOS 7.9 真机全量部署验收清单

在 **两台（或以上）CentOS 7.9** 云主机/虚拟机上，用仓库自带脚本完成主控 + 首台节点的安装，并逐项打勾。业务侧 HTTP-01、DNS、证书等深度验收见 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)（F1–F8）。

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
php -r 'echo PHP_VERSION, "\n";'
mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT COUNT(*) FROM redi_admin.admin_users;"
curl -sI -o /dev/null -w '%{http_code}\n' "http://127.0.0.1/admin"
ss -lntp | egrep ':80|:443|:3306' || true
sudo firewall-cmd --list-all
```

| 检查项 | 期望 |
|--------|------|
| `firewalld` / `mariadb` / `php-fpm` / `nginx` / `redi-queue` | 均为 `active` |
| PHP 版本 | **8.0.x** |
| `admin_users` | ≥ 1 |
| `curl` 后台 | **302** 或 **200** |
| 3306 | 监听；**不要**对 `0.0.0.0/0` 在安全组全网开放 |
| firewalld | 含 `ssh` `http` `https`；对节点 IP 有 mysql 相关 rich rule（若已配置 `NODE_IPS`） |
| 节点 DB 权限 | 远程账号 `redi_node` 仅 EXECUTE `redi_node_bump_skip`（`grant-node-mysql.sh`；导入 SQL 后执行） |

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

若主控部署时 `NODE_IPS` 为空，补写后重跑：

```bash
# 主控 vars.env 加上 NODE_IPS="5.6.7.8"
sudo bash scripts/centos7/deploy-master.sh
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
REDI_MASTER_URL="http://1.2.3.4"
REDI_NODE_TOKEN="后台复制的Token"
```

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
| `config.json` `server_ip` | 等于 `5.6.7.8` |
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

- [ ] 客户域 CNAME 预检「已指向」。
- [ ] 单域「申请证书」后状态到「已生效」。
- [ ] `https://客户域/` 证书与跳转正常。

---

## 7. 常见问题速查

| 现象 | 处理 |
|------|------|
| 节点 `deploy-node.sh` 连不上 MySQL | 主控 `NODE_IPS` 是否含节点公网 IP；主控是否重跑 `deploy-master.sh`；云安全组 3306 |
| 节点无心跳 | `REDI_MASTER_URL` 是否可达；Token 是否正确；`systemctl status redi-multi` 日志 |
| 后台 502/空白 | `systemctl status php-fpm nginx`；`/opt/301/master/storage` 权限属 `nginx` |
| 重复跑主控脚本后 HTTPS 没了 | 是否曾去掉 `301-master-managed`；HTTPS 建议单独 `conf.d` 文件 |
| `yum` 很慢或 IPv6 失败 | 脚本已尝试 `ip_resolve=4`；检查机器 IPv4 出口 |

---

## 8. 开发机容器冒烟（非真机替代）

仅验证包与 yum/migrate，**不能**代替本节 systemd/firewalld 验收：

```bash
sudo bash scripts/centos7/run-on-centos7-host.sh
```

见 [DEPLOY-centos7.md](../DEPLOY-centos7.md) §7。

---

**签收**：主控 §1–3、节点 §4–5 全部打勾后，可认为 **CentOS 7.9 脚本化部署验收通过**；业务 F 项以 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) 为准。
