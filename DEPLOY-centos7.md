# CentOS 7 部署

这是 [DEPLOY.md](DEPLOY.md) 在 CentOS 7 上的命令。架构、端口和上线顺序以那篇为准。填好 IP 和密码的示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。

假设主控安装到 `/opt/301`，节点程序解压到 `/opt/redi-multi/redi-multi`。PHP 使用 Remi 的 **8.0**。不要安装 PHP 8.1 或更高版本，当前 `composer.json` 不接受。

## 0. 变量

在主控上先导出这些值，后面的命令会用到：

```bash
MASTER_IP="主控公网IP"
DB_PASS="redi_admin的强密码"
MYSQL_ROOT_PASS="MySQL root 密码"
GIT_REPO="https://github.com/freejbgo/301.git"
INSTALL_ROOT="/opt/301"
```

## 1. 主控

### 1.1 软件

```bash
sudo yum install -y epel-release
sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm
sudo yum install -y yum-utils
sudo yum-config-manager --enable remi-php80
sudo yum install -y git unzip nginx mariadb-server mariadb \
  php php-fpm php-mysqlnd php-mbstring php-xml php-json php-zip php-gd php-bcmath \
  firewalld
sudo systemctl enable --now firewalld mariadb nginx php-fpm
```

看 PHP-FPM 实际监听地址，Nginx 里的 `fastcgi_pass` 必须和它一致：

```bash
grep -E '^listen' /etc/php-fpm.d/www.conf
```

### 1.2 代码、数据库

```bash
sudo mkdir -p "$INSTALL_ROOT"
sudo git clone "$GIT_REPO" "$INSTALL_ROOT"
cd "$INSTALL_ROOT"
sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh
```

把 `db/mysql_setup.example.sql` 里的 `change_this_db_password` 换成 `$DB_PASS` 后导入，或：

```bash
sed "s/change_this_db_password/${DB_PASS}/g" db/mysql_setup.example.sql | mysql -u root -p
mysql -u root -p redi_admin < db/redi_admin.sql
```

每台节点的出口 IP 授权一次。不要对全网开放 3306。

```bash
export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
sudo -E bash scripts/centos7/grant-node-mysql.sh "节点出口IP" "$DB_PASS"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "节点出口IP"
```

多台节点：

```bash
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
# 编辑 vars.env 的 NODE_IPS
sudo bash scripts/centos7/grant-nodes-from-vars.sh
```

```bash
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### 1.3 Laravel

```bash
cd "$INSTALL_ROOT/master/master"
cp .env.example .env
sed -i "s|change_this_db_password|${DB_PASS}|g" .env
```

有域名和证书时，把 `APP_URL` 改成 `https://主控域名`，`ADMIN_HTTPS` 保持 `true`。暂时只用 IP 访问时：

```bash
sed -i "s|https://your-master-domain.com|http://${MASTER_IP}|g" .env
sed -i 's/ADMIN_HTTPS=true/ADMIN_HTTPS=false/' .env
```

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
sudo chown -R nginx:nginx storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

SELinux 开启时，允许 Nginx 连接本机 PHP-FPM，并让网站用户能写 `storage`：

```bash
sudo setsebool -P httpd_can_network_connect 1
sudo chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache
```

Nginx 站点 `/etc/nginx/conf.d/301-master.conf`。下面的 sock 请改成 `www.conf` 里的 `listen`：

```nginx
server {
    listen 80;
    server_name _;
    root /opt/301/master/master/public;
    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

打开 `http://主控IP/admin`。用户名 `admin`，密码 `Redi301@Install2026`，登录后立即修改。

有域名后把 `.env` 的 `APP_URL` 改为 https，`ADMIN_HTTPS=true`，再用 certbot 或已有证书给这个 Nginx 站点配 HTTPS。

### 1.4 队列和续期

```bash
sudo tee /etc/systemd/system/redi-queue.service <<'EOF'
[Unit]
Description=301 Master queue worker (certificates)
After=network.target mariadb.service

[Service]
Type=simple
User=nginx
WorkingDirectory=/opt/301/master/master
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now redi-queue
```

```bash
echo '10 3 * * * cd /opt/301/master/master && php artisan redi:renew-certificates >> /var/log/redi-renew.log 2>&1' | sudo tee /etc/cron.d/redi-renew
sudo chmod 644 /etc/cron.d/redi-renew
```

仓库里的 `scripts/systemd/redi-queue.service.example` 是同一份单元，用户写成了 Debian 的 `www-data`。CentOS 用上面的 `nginx`。

## 2. 每台节点

先在主控上完成该节点出口 IP 的授权和 firewalld 放行。

```bash
NODE_PUBLIC_IP="本机主公网IP"
MASTER_IP="主控公网IP"
DB_PASS="与主控相同"
INSTALL_ROOT="/opt/redi-multi"

sudo yum install -y tar firewalld
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-port=80/tcp
sudo firewall-cmd --permanent --add-port=443/tcp
sudo firewall-cmd --reload

sudo mkdir -p "$INSTALL_ROOT"
# 在主控上：scp /opt/301/node/redi-multi.tar.gz 节点:/tmp/
sudo tar xzf /tmp/redi-multi.tar.gz -C "$INSTALL_ROOT"
cd "$INSTALL_ROOT/redi-multi"

cat > env.sh <<EOF
REDI_DB_HOST=${MASTER_IP}
REDI_DB_PORT=3306
REDI_DB_USER=redi_admin
REDI_DB_PASSWORD=${DB_PASS}
REDI_DB_NAME=redi_admin
REDI_MASTER_URL=https://主控域名
REDI_NODE_TOKEN=后台节点管理里的一次性Token
EOF
chmod 600 env.sh

sed -i "s/YOUR_NODE_PUBLIC_IP/${NODE_PUBLIC_IP}/g" config.json
# 再用编辑器把 default_redirect_url 改成默认跳转页

sudo setcap cap_net_admin+ep bin/redi-multi
sudo cp /opt/301/scripts/centos7/redi-multi.service.example /etc/systemd/system/redi-multi.service
```

节点上如果没有仓库，把主控的 `scripts/centos7/redi-multi.service.example` 用 scp 拷过来再安装。单元里的路径已经是 `/opt/redi-multi/redi-multi`，与上面的解压位置一致。`env.sh` 不要加 `export`，CentOS 7 的 systemd 不认这个前缀。

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now redi-multi
sudo systemctl status redi-multi
```

自测连库：

```bash
mysql -h "$MASTER_IP" -u redi_admin -p"$DB_PASS" -e "SELECT 1" redi_admin
```

同一台机器还有别的公网 IP 时，不要再部署第二个进程。回到后台这条节点，把那些 IP 填进「其他公网 IP」。

## 3. 以后加一台节点

在主控：

```bash
cd /opt/301
export MYSQL_ROOT_PASSWORD='root密码'
sudo -E bash scripts/centos7/grant-node-mysql.sh "新节点出口IP" "库密码"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "新节点出口IP"
```

在新机器上重复第 2 节。然后在后台「节点管理」新建一条节点，把 Token 写入这台机器的 `env.sh`，重启 `redi-multi`。pool 的 A 记录要包含新 IP：手动模式去 DNS 厂商添加，API 模式在「DNS 与节点池」再同步一次。

## 4. MySQL 日志里的 Name or service not known

这是 MySQL 在对客户端 IP 做反向解析。`apply-mysql-skip-name-resolve.sh` 会把 `db/my.cnf.d/redi-skip-name-resolve.cnf` 放到 `/etc/my.cnf.d/` 并重启数据库。启用之后授权只能写 IP，不能写主机名。
