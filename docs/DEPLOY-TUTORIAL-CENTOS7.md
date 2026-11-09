# CentOS 7 从零部署教程（裸机 7.9）

按 **步骤 1 → 7** 顺序做即可上线。生产机 **只需 CentOS 7.x（常用 7.9）**，**不要装 Docker**。细节与可选章节见 [DEPLOY-centos7.md](../DEPLOY-centos7.md)；填空示例见 [DEPLOY-centos7-example.md](../DEPLOY-centos7-example.md)。

| 角色 | 系统 | 需要什么 |
|------|------|----------|
| **发版机** | 任意 Linux（或另一台 7.9） | `git`、**composer**、**Go**；用来打 tar，不必是生产机 |
| **主控** | CentOS 7.9 | 公网 IPv4；开放 **22 / 80 / 443**；**不**对公网开放 3306 |
| **每台节点** | CentOS 7.9 | 公网 IPv4；开放 **22 / 80 / 443** |

程序装好后路径：主控 `/opt/301/master`，节点 `/opt/redi-multi/redi-multi`。仓库请克隆到 **`/root/301`** 这类目录，**不要**克隆到 `/opt/301`。

---

## 步骤 1：在发版机打出发布包

在发版机上拿到本仓库（`git clone`），进入仓库根目录：

```bash
cd /path/to/301
bash scripts/dev-preflight.sh
bash scripts/build-release-tarballs.sh
```

得到（已 gitignore，勿提交）：

- `master/master.tar.gz` — 主控（含 `vendor`，无 `.env`）
- `node/redi-multi.tar.gz` — 节点（静态 `bin/redi-multi`）

无 Docker 时 `dev-preflight` 会跳过仅依赖容器的 DB 校验，**不影响**下面在 7.9 真机部署。

更多说明：[RELEASE-PACKAGES.md](RELEASE-PACKAGES.md)。

---

## 步骤 2：主控机准备仓库与发布包

SSH 登录 **主控**（CentOS 7.9），安装 `git`（若还没有）：

```bash
yum install -y git
git clone https://github.com/freejbgo/301.git /root/301
cd /root/301
```

从发版机 **上传两个 tar**（把 IP 换成发版机或你本机）：

```bash
# 在发版机上执行（示例）
scp master/master.tar.gz node/redi-multi.tar.gz root@<主控IP>:/tmp/
```

主控上确认文件存在：

```bash
ls -lh /tmp/master.tar.gz /tmp/redi-multi.tar.gz
```

---

## 步骤 3：主控填写 vars.env 并一键部署

仍在主控 `/root/301`：

```bash
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

**至少要改：**

| 变量 | 说明 |
|------|------|
| `MASTER_IP` | 本机公网 IPv4 |
| `DB_PASS` / `MYSQL_ROOT_PASS` | 强密码（勿含空格、引号、`$`、`#` 等，见 example 注释） |
| `MASTER_URL` | 尚无 HTTPS 证书时：`http://主控IP` |
| `ADMIN_HTTPS` | 尚无证书时：`false` |
| `NODE_IPS` | 已知节点公网 IPv4，空格分隔；第一台节点可先填上 |
| `REDI_MASTER_TAR` | **`/tmp/master.tar.gz`**（步骤 2 上传的路径） |
| `REDI_NODE_TAR` | 可留空；节点机部署时会用自己的 tar |

执行主控脚本（**必须 root**）：

```bash
sudo bash scripts/centos7/deploy-master.sh
```

脚本会：换 vault 源、装 PHP 8.0 / Nginx / MariaDB 10.6、空库导入 `db/redi_admin.sql`、解压主控包到 `/opt/301/master`、配置队列与 Cron、按 `NODE_IPS` 放行 3306 等。结束时日志里应出现 **`使用主控发布包: /tmp/master.tar.gz`**（或你设置的路径）。

浏览器打开 **`http://<主控IP>/admin`**，默认 `admin` / `Redi301@Install2026`，会 **强制改密** 后再进菜单。

绿场数据库说明：[GREENFIELD-INSTALL.md](GREENFIELD-INSTALL.md)。

---

## 步骤 4：后台创建节点并复制 Token

在主控后台：

1. 打开 **「节点管理」** → 新建节点。
2. **主公网 IP** 填节点机的 IPv4（须与 `vars.env` 里 `NODE_IPS` 一致；若当时未写，改 `NODE_IPS` 后在本机再跑一次 `sudo bash scripts/centos7/deploy-master.sh` 或 `grant-nodes-from-vars.sh`）。
3. 复制 **只显示一次** 的 **Token**，保存到记事本。

操作说明：[CONSOLE.md](CONSOLE.md)。

---

## 步骤 5：节点机准备并部署

SSH 登录 **节点**（CentOS 7.9）：

```bash
yum install -y git
git clone https://github.com/freejbgo/301.git /root/301
cd /root/301
```

上传节点 tar（若主控 `/tmp` 已有 `redi-multi.tar.gz`，也可 `scp` 从主控拷到节点）：

```bash
scp root@<发版机或主控>:/tmp/redi-multi.tar.gz /tmp/
```

复制并编辑变量（可与主控共用一份逻辑，**每台节点 `NODE_PUBLIC_IP` 不同**）：

```bash
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
vi scripts/centos7/vars.env
```

**节点额外要填：**

| 变量 | 说明 |
|------|------|
| `MASTER_IP` | 主控公网 IPv4（写入 `env.sh` 的 `REDI_DB_HOST`，用于连库与部署后 `redi_node_bump_skip` 冒烟） |
| `DB_PASS` | 与主控 `vars.env` 相同的 MySQL 密码（`redi_node` 用户，仅 `EXECUTE` 存储过程） |
| `NODE_PUBLIC_IP` | 本节点公网 IPv4 |
| `DEFAULT_REDIRECT_URL` | 未命中域名时的默认跳转 URL |
| `REDI_MASTER_URL` | 生产用 **`https://主控域名`**；主控尚无证书时 lab 可 `REDI_MASTER_INSECURE=1` + `http://主控IP` |
| `REDI_NODE_TOKEN` | 步骤 4 复制的 Token |
| `REDI_NODE_TAR` | **`/tmp/redi-multi.tar.gz`** |

部署：

```bash
sudo bash scripts/centos7/deploy-node.sh
```

应看到 **`使用节点发布包: /tmp/redi-multi.tar.gz`**，且 `systemctl is-active redi-multi` 为 `active`。

**最小文件集（不想整库 clone 时）：** 节点至少要有 `scripts/centos7/deploy-node.sh`、`lib.sh`、`vars.env`、以及 `redi-multi.tar.gz`；主控至少要有 `scripts/centos7/`、`db/redi_admin.sql`、主控 tar。推荐仍 clone 整库，便于升级脚本与文档路径一致。

---

## 步骤 6：后台配置 DNS、域名与证书

仍在主控后台（需超级管理员 / 租户分工见 CONSOLE §8）：

0. （可选）登录页或侧栏切换 **简 / 繁 / 英**；系统外观按语言 Tab 填写品牌名（见 [I18N.md](I18N.md)）。
1. **「DNS 与节点池」** — 填写平台主域名。
2. **DNS 记录** — 手动模式按页面表给 `pool` 加 **A**（节点 IPv4）；API 模式点同步（失败见 CONSOLE §2）。
3. **分组 / 域名** — 超级管理员建分组；租户添加客户域名，把 **CNAME 目标** 发给客户（客户 CNAME 到 `{token}.平台域`，不要 A 到节点 IP）。
4. 预检 **已指向** 后，点 **申请证书**。

客户侧文案：[CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md)。边缘池验收：[ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)。

主控有 HTTPS 域名后：改主控 `.env` 的 `APP_URL`、节点 `REDI_MASTER_URL` 为 **https://**，去掉 `REDI_MASTER_INSECURE`，节点再跑一次 `deploy-node.sh`。

---

## 步骤 7：验收（主控与节点）

主控上：

```bash
systemctl is-active nginx php-fpm mariadb redi-queue
cd /opt/301/master && sudo -u nginx php artisan redi:health
```

节点上：

```bash
systemctl is-active redi-multi
getcap /opt/redi-multi/redi-multi/bin/redi-multi | grep -q cap_net_admin
```

完整打勾清单：[ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md)、[LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)。

---

## 以后：加节点 / 换版本

| 场景 | 做法 |
|------|------|
| **加一台节点** | 主控 `NODE_IPS` 加 IP → `deploy-master.sh` 或 `grant-nodes-from-vars.sh` → 后台新建节点 → 新机器步骤 5 |
| **只换程序** | 发版机重新 `build-release-tarballs.sh` → `scp` → 改 `REDI_*_TAR` → 主控/节点重跑对应 `deploy-*.sh`（或见 [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)） |
| **lagran 异常** | `scripts/centos7/lagran-diagnose.sh`（见 DEPLOY-centos7 §8） |

---

## 常见错误

| 现象 | 处理 |
|------|------|
| `未找到主控发布包` / 本机无 composer | 在发版机打好 tar，`scp` 到本机并设置 **`REDI_MASTER_TAR`** |
| 节点连库失败 | 确认主控 `NODE_IPS` 含该节点出口 IP，且已跑过 `deploy-master` / grant |
| Agent 不注册 | 检查 `REDI_NODE_TOKEN`、`REDI_MASTER_URL`（生产须 https） |
| 克隆到了 `/opt/301` | 换目录 clone；程序目录由 tar 解压到 `/opt/301/master`，不要与 git 仓库混放 |
