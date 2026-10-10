# CentOS 7 部署示例（占位 IP，复制即用）

主控 **1.2.3.4** + 第一台节点 **5.6.7.8** 的完整示例。把 IP 和密码换成你的即可。

详细说明见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。变量文件模板：[scripts/centos7/vars.example.env](scripts/centos7/vars.example.env)。

---

## 0. 变量（主控、节点都要对齐）

```bash
MASTER_IP="1.2.3.4"
NODE1_IP="5.6.7.8"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
GIT_REPO="https://github.com/freejbgo/301.git"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
DEFAULT_REDIRECT_URL="https://www.example.com"
```

| 占位 | 含义 |
|------|------|
| `1.2.3.4` | 主控公网 IP |
| `5.6.7.8` | 节点公网 IP（DNS A 记录指到这里） |
| `DB_PASS` | `redi_admin` 密码，主控 `.env` 与节点 `env.sh` 必须相同 |

---

## 一、主控（1.2.3.4）

### 1. 软件与代码

```bash
export MASTER_IP="1.2.3.4"
export DB_PASS="YourStrongDbPass_2026"
export MYSQL_ROOT_PASS="YourMysqlRootPass"
export MASTER_INSTALL_ROOT="/opt/301"
export GIT_REPO="https://github.com/freejbgo/301.git"

sudo yum install -y epel-release
sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm
sudo yum install -y yum-utils
sudo yum-config-manager --enable remi-php80
sudo yum install -y git nginx mariadb-server mariadb \
  php php-fpm php-mysqlnd php-mbstring php-xml php-json php-zip php-gd php-bcmath \
  firewalld
sudo systemctl enable --now firewalld mariadb nginx php-fpm

sudo mkdir -p "$MASTER_INSTALL_ROOT"
sudo git clone "$GIT_REPO" "$MASTER_INSTALL_ROOT"
cd "$MASTER_INSTALL_ROOT"
```

### 2. MySQL 关闭 DNS 反查

```bash
cd "$MASTER_INSTALL_ROOT"
sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh
mysql -u root -p -e "SHOW VARIABLES LIKE 'skip_name_resolve';"
```

### 3. 建库并导入

```bash
sed "s/change_this_db_password/${DB_PASS}/g" db/mysql_setup.example.sql | mysql -u root -p
mysql -u root -p redi_admin < db/redi_admin.sql
```

### 4. 授权节点 1 访问 3306

方式 A（单节点）：

```bash
export NODE1_IP="5.6.7.8"
export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
sudo -E bash scripts/centos7/grant-node-mysql.sh "$NODE1_IP" "$DB_PASS"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "$NODE1_IP"
```

方式 B（多台，推荐）：编辑 `scripts/centos7/vars.env` 后：

```bash
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
# vi scripts/centos7/vars.env  # 填 NODE_IPS="5.6.7.8 9.10.11.12" 等
sudo bash scripts/centos7/grant-nodes-from-vars.sh
```

### 5. 主控防火墙（勿对 0.0.0.0/0 开放 3306）

```bash
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### 6. Laravel 后台（暂无域名）

```bash
cd "$MASTER_INSTALL_ROOT/master"
cp .env.example .env
sed -i "s|change_this_db_password|${DB_PASS}|g" .env
sed -i "s|https://your-master-domain.com|http://${MASTER_IP}|g" .env
sed -i 's/ADMIN_HTTPS=true/ADMIN_HTTPS=false/' .env

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer install --no-dev --optimize-autoloader
php artisan key:generate
sudo chown -R nginx:nginx storage bootstrap/cache
```

Nginx（`root` 路径与 `MASTER_INSTALL_ROOT` 一致）：

```bash
sudo tee /etc/nginx/conf.d/301-master.conf <<NGINX
server {
    listen 80;
    server_name _;
    root ${MASTER_INSTALL_ROOT}/master/public;
    index index.php;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php-fpm/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX
sudo nginx -t && sudo systemctl reload nginx
```

- 访问：`http://1.2.3.4/admin`
- 登录：`admin` / `Redi301@Install2026` → **立即改密码**

---

## 二、节点 1（5.6.7.8）

**先确认主控已对 `5.6.7.8` 执行 grant + firewalld。**

```bash
export NODE_PUBLIC_IP="5.6.7.8"
export MASTER_IP="1.2.3.4"
export DB_PASS="YourStrongDbPass_2026"
export NODE_INSTALL_ROOT="/opt/redi-multi"
export GIT_REPO="https://github.com/freejbgo/301.git"
export DEFAULT_REDIRECT_URL="https://www.example.com"

sudo yum install -y tar firewalld git
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-port=80/tcp
sudo firewall-cmd --permanent --add-port=8080/tcp
sudo firewall-cmd --reload

sudo mkdir -p "$NODE_INSTALL_ROOT"
cd /tmp
git clone "$GIT_REPO" 301-repo
sudo tar xzf 301-repo/node/redi-multi.tar.gz -C "$NODE_INSTALL_ROOT"
cd "$NODE_INSTALL_ROOT/redi-multi"

cat > env.sh <<EOF
export REDI_DB_HOST=${MASTER_IP}
export REDI_DB_PORT=3306
export REDI_DB_USER=redi_admin
export REDI_DB_PASSWORD=${DB_PASS}
export REDI_DB_NAME=redi_admin
EOF
chmod 600 env.sh

sed -i "s/YOUR_NODE_PUBLIC_IP/${NODE_PUBLIC_IP}/g" config.json
sed -i "s|https://www.example.com|${DEFAULT_REDIRECT_URL}|g" config.json

sudo setcap cap_net_admin+ep bin/redi-multi 2>/dev/null || true

sudo cp /tmp/301-repo/scripts/centos7/redi-multi.service.example /etc/systemd/system/redi-multi.service
sudo sed -i "s|/opt/redi-multi/redi-multi|${NODE_INSTALL_ROOT}/redi-multi|g" /etc/systemd/system/redi-multi.service
sudo systemctl daemon-reload
sudo systemctl enable --now redi-multi
sudo systemctl status redi-multi
```

自测连库：

```bash
source env.sh
mysql -h "$REDI_DB_HOST" -u "$REDI_DB_USER" -p"$REDI_DB_PASSWORD" -e "SELECT 1" "$REDI_DB_NAME"
```

---

## 三、后台与 DNS

1. 主控后台 → **分组** → 新建分组。
2. **域名管理** → `original_url` 填访客访问的域名（如 `jump.example.com`），`skip_url` 填目标 `http(s)://...`。
3. 该域名 DNS **A 记录** → `5.6.7.8`。
4. `config.json` 的 `server_ip` 必须是本节点公网 IP（示例已为 `5.6.7.8`）。

---

## 四、加第 2～5 台节点

| 步骤 | 操作 |
|------|------|
| 主控 | 把新 IP 加入 `vars.env` 的 `NODE_IPS`，或单独跑 `grant-node-mysql.sh` + `firewalld-allow-mysql-from-node.sh` |
| 新机器 | 重复 **第二节**，改 `NODE_PUBLIC_IP` 与 `config.json` |
| 后台 / DNS | 域名指到新节点 IP |

---

## 五、排错速查

| 现象 | 检查 |
|------|------|
| 节点连不上库 | 主控是否对**节点公网 IP** 授权；`REDI_DB_HOST` 是否为主控 IP |
| 不跳转 | DNS 是否到节点；后台 `original_url` 是否与访问 host 一致 |
| `api.js` 失败 | 8080 是否开放；`server_ip` 是否为该节点公网 IP |
