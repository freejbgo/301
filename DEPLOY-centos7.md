# CentOS 7.x 部署：1 台主控 + 1～5 台节点（无宝塔）

**占位 IP 逐步示例（1.2.3.4 主控 + 5.6.7.8 节点）** → [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)  
**批量节点授权** → 复制 [scripts/centos7/vars.example.env](scripts/centos7/vars.example.env) 为 `vars.env`，执行 `grant-nodes-from-vars.sh`。

节点数量不固定时：**每增加一台节点，重复文末「新增节点」三步**即可。

假设：

- 主控：装 **MySQL + Nginx + PHP + Laravel 后台**
- 节点：公网 IP，跑 **redi-multi**（80/8080），用环境变量连主控上的 MySQL
- 主控域名暂时没有：先用 **主控公网 IP** 访问后台，有域名后再改 `.env` 并配 HTTPS

以下把密码、IP 写成变量，整段复制到服务器后**只改变量**再执行。

---

## 0. 在你本机先定好这些值

```bash
# === 改成你的 ===
MASTER_IP="1.2.3.4"              # 主控公网 IP
DB_PASS="请换成强密码"              # MySQL 用户 redi_admin 的密码（主控与所有节点相同）
MYSQL_ROOT_PASS="请换成 root 密码"  # 仅主控安装库时用

# 已有节点 IP（没有就先留空，后面加）
NODE_IPS="5.6.7.8 9.10.11.12"

GIT_REPO="https://github.com/freejbgo/301.git"
INSTALL_ROOT="/opt/301"
```

---

## 1. 主控机（CentOS 7）

### 1.1 基础软件

```bash
sudo yum install -y epel-release
sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm
sudo yum install -y yum-utils
sudo yum-config-manager --enable remi-php80
sudo yum install -y git nginx mariadb-server mariadb \
  php php-fpm php-mysqlnd php-mbstring php-xml php-json php-zip php-gd php-bcmath \
  firewalld
sudo systemctl enable --now firewalld mariadb nginx php-fpm
```

（若坚持用 MySQL 官方包，可改装 `mysql-community-server`，下文 `mariadb` 服务名改为 `mysqld`。）

### 1.2 拉代码

```bash
sudo mkdir -p "$INSTALL_ROOT"
sudo git clone "$GIT_REPO" "$INSTALL_ROOT"
cd "$INSTALL_ROOT"
```

### 1.3 MySQL：关闭 DNS 反查（不用手改 my.cnf）

```bash
sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh
mysql -u root -p -e "SHOW VARIABLES LIKE 'skip_name_resolve';"
# 应看到 skip_name_resolve | ON
```

### 1.4 建库、主控本机账号、导入数据

```bash
# 编辑 db/mysql_setup.example.sql 里的 change_this_db_password 为 $DB_PASS 后执行，或：
sed "s/change_this_db_password/${DB_PASS}/g" db/mysql_setup.example.sql | mysql -u root -p

mysql -u root -p redi_admin < db/redi_admin.sql
```

### 1.5 为每个节点 IP 授权 + 防火墙（节点从公网连主控 3306 时）

对 `NODE_IPS` 里每一个 IP 执行（**新增节点时重复**）：

```bash
cd "$INSTALL_ROOT"
export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
sudo -E bash scripts/centos7/grant-node-mysql.sh "5.6.7.8" "$DB_PASS"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "5.6.7.8"
```

主控对外开放 Web，**不要**对全网开放 3306：

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
# 不要执行 firewall-cmd --add-port=3306/tcp 对 0.0.0.0/0
sudo firewall-cmd --reload
```

### 1.6 Laravel 主控

```bash
cd "$INSTALL_ROOT/master"
cp .env.example .env
sed -i "s|change_this_db_password|${DB_PASS}|g" .env
sed -i "s|https://your-master-domain.com|http://${MASTER_IP}|g" .env
# 暂无域名时先关 HTTPS 链接生成，有域名再改回 true
sed -i 's/ADMIN_HTTPS=true/ADMIN_HTTPS=false/' .env

# 安装 Composer（若未安装）
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

composer install --no-dev --optimize-autoloader
php artisan key:generate
sudo chown -R nginx:nginx storage bootstrap/cache
```

Nginx 站点示例（`/etc/nginx/conf.d/301-master.conf`）：

```nginx
server {
    listen 80;
    server_name _;
    root /opt/301/master/public;
    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

浏览器访问：`http://主控IP/admin`（Dcat 默认后台路径以你安装为准，常见为 `/admin`）。

- 用户名：`admin`
- 密码：`Redi301@Install2026` → **登录后立即修改**

有域名后：改 `.env` 的 `APP_URL`、`ADMIN_HTTPS=true`，用 certbot 或自有证书配 HTTPS。

**边缘池 / 证书（可选，见 [docs/DEVELOPMENT-EDGE-POOL-HTTPS.md](docs/DEVELOPMENT-EDGE-POOL-HTTPS.md)）**：

```bash
cd /opt/301/master
# .env：QUEUE_CONNECTION=database（默认示例已改）
php artisan migrate --force   # 升级库时
php artisan queue:work --sleep=3 --tries=3 &
# 证书续期：每天一次即可，例如 03:10：
# 10 3 * * * cd /opt/301/master && php artisan redi:renew-certificates >> /var/log/redi-renew.log 2>&1
```

后台配置「DNS 与节点池」「节点管理」；租户域名 CNAME 后批量/单个申请证书。

---

## 2. 每一台节点机（CentOS 7）

在**该节点**上执行（`NODE_PUBLIC_IP` 填**本机**公网 IP）：

```bash
NODE_PUBLIC_IP="5.6.7.8"
MASTER_IP="1.2.3.4"
DB_PASS="与主控相同"
INSTALL_ROOT="/opt/redi-multi"

sudo yum install -y tar firewalld
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-port=80/tcp
sudo firewall-cmd --permanent --add-port=443/tcp
sudo firewall-cmd --permanent --add-port=8080/tcp
sudo firewall-cmd --reload

sudo mkdir -p "$INSTALL_ROOT"
cd "$INSTALL_ROOT"
# 从主控 scp 或 git clone 后只取 node 目录均可，示例用 clone：
sudo git clone https://github.com/freejbgo/301.git repo
sudo tar xzf repo/node/redi-multi.tar.gz -C "$INSTALL_ROOT"
cd "$INSTALL_ROOT/redi-multi"

cat > env.sh <<EOF
export REDI_DB_HOST=${MASTER_IP}
export REDI_DB_PORT=3306
export REDI_DB_USER=redi_admin
export REDI_DB_PASSWORD=${DB_PASS}
export REDI_DB_NAME=redi_admin
export REDI_MASTER_URL=https://你的主控域名或暂用http://${MASTER_IP}
export REDI_NODE_TOKEN=在后台节点管理创建节点后保存的一次性Token
EOF
chmod 600 env.sh

# 改 config.json 里 server_ip、default_redirect_url
sudo sed -i "s/YOUR_NODE_PUBLIC_IP/${NODE_PUBLIC_IP}/g" config.json
# default_redirect_url 请用 vi 改成你的默认跳转页

# 需 root 跑 lagran 时：
sudo setcap cap_net_admin+ep bin/redi-multi 2>/dev/null || true

sudo cp repo/scripts/centos7/redi-multi.service.example /etc/systemd/system/redi-multi.service
sudo sed -i "s|/opt/redi-multi/redi-multi|${INSTALL_ROOT}/redi-multi|g" /etc/systemd/system/redi-multi.service
sudo systemctl daemon-reload
sudo systemctl enable --now redi-multi
sudo systemctl status redi-multi
```

**务必先在主控**对该节点 IP 执行过 `grant-node-mysql.sh` 和 `firewalld-allow-mysql-from-node.sh`，否则节点连不上库。

---

## 3. 新增第 N 台节点（以后加机器只做这些）

在**主控**：

```bash
cd /opt/301
export MYSQL_ROOT_PASSWORD='你的root密码'
sudo -E bash scripts/centos7/grant-node-mysql.sh "新节点公网IP" "redi_admin密码"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "新节点公网IP"
```

在新节点上：重复 **第 2 节**（换 `NODE_PUBLIC_IP`）。

---

## 4. MySQL「IP could not be resolved」说明

日志里的 `IP address 'x.x.x.x' could not be resolved` 是 MySQL 对客户端 IP 做**反向 DNS**。连接多时会拖慢甚至拖死库。

本仓库已提供：

| 文件 | 作用 |
|------|------|
| `db/my.cnf.d/redi-skip-name-resolve.cnf` | `skip-name-resolve` + `skip-external-locking` |
| `scripts/centos7/apply-mysql-skip-name-resolve.sh` | 一键拷到 `/etc/my.cnf.d/` 并重启库 |

启用后，**不能用主机名做 GRANT**，必须用 IP；节点授权请用 `grant-node-mysql.sh`。

---

更通用的说明见 [DEPLOY.md](DEPLOY.md)、[SECURITY.md](SECURITY.md)。
