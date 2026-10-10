# 301 系统部署说明（简版）

按顺序操作即可；代码里能配好的已写好，**你只需要在服务器上改密码和 IP**。

**CentOS 7、一台主控 + 多台公网节点**：见 **[DEPLOY-centos7.md](DEPLOY-centos7.md)**（含复制粘贴命令、MySQL 关闭 DNS 反查一键脚本）。

## 1. 数据库（主控服务器）

```bash
mysql -u root -p < db/mysql_setup.example.sql   # 先编辑其中的密码
mysql -u root -p redi_admin < db/redi_admin.sql
```

- 初始后台：**用户名 `admin`，密码 `Redi301@Install2026`** → 登录后立刻改成你自己的强密码。
- 库表已含 `(admin_id, original_url)` 唯一约束，**不用再执行任何补丁 SQL**。
- **务必**：MySQL `3306` 只对主控内网/本机开放，不要对公网 `0.0.0.0/0` 开放。

## 2. 主控（master 目录）

```bash
cd master
cp .env.example .env
# 编辑 .env：APP_URL、DB_*、执行 php artisan key:generate
composer install --no-dev --optimize-autoloader
php artisan key:generate
# 配置网站根目录为 master/public，并启用 HTTPS
```

`.env` 已默认 `APP_DEBUG=false`、`ADMIN_HTTPS=true`。

## 3. 节点（node/redi-multi）

```bash
cd node/redi-multi
cp env.example env.sh && vi env.sh    # 填写 REDI_DB_*（与主控同一库，建议节点专用 DB 账号仅内网）
vi config.json                         # server_ip 改为本机公网 IP，default_redirect_url 改为你的默认页
source env.sh
go build -o redi-multi .
./redi-multi
```

- 节点通过环境变量连库，**仓库里不再包含数据库密码**。
- `api.js` 已做 JSON 安全输出与按 IP 限速（约 120 次/分钟）；仍建议用防火墙限制 `8080` 仅必要来源访问。

## 4. 你必须在机房/面板完成的（代码无法代替）

| 项 | 做法 |
|----|------|
| HTTPS | 主控域名申请证书（宝塔/Let’s Encrypt 等） |
| 防火墙 | 关闭公网 3306；仅开放 80/443（及节点所需端口） |
| 改密码 | 后台 admin、MySQL `redi_admin` 用户、`.env` 里 `DB_PASSWORD` |
| 旧环境 | 若曾用过旧节点包内置数据库密码，务必在 MySQL 里 **轮换密码** 并同步 `env.sh` / `.env` |

防火墙示例（Ubuntu `ufw`，按你实际 IP 改）：

```bash
ufw default deny incoming
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
# 节点机：若访客浏览器要访问节点上的 8080（api.js），再开放；否则用 Nginx 反代到 127.0.0.1:8080 可不对外开放 8080
ufw enable
```

更完整说明见 [SECURITY.md](SECURITY.md)。
