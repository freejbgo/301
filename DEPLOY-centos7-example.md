# CentOS 7 填空示例

主控 `1.2.3.4`，第一台节点 `5.6.7.8`。把 IP 和密码换成你的。步骤说明见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。

```bash
MASTER_IP="1.2.3.4"
NODE1_IP="5.6.7.8"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
GIT_REPO="https://github.com/freejbgo/301.git"
MASTER_INSTALL_ROOT="/opt/301"
```

## 主控 1.2.3.4

```bash
export MASTER_IP="1.2.3.4"
export DB_PASS="YourStrongDbPass_2026"
export MYSQL_ROOT_PASS="YourMysqlRootPass"
export GIT_REPO="https://github.com/freejbgo/301.git"
export MASTER_INSTALL_ROOT="/opt/301"

sudo yum install -y epel-release
sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm
sudo yum install -y yum-utils
sudo yum-config-manager --enable remi-php80
sudo yum install -y git unzip nginx mariadb-server mariadb \
  php php-fpm php-mysqlnd php-mbstring php-xml php-json php-zip php-gd php-bcmath \
  firewalld
sudo systemctl enable --now firewalld mariadb nginx php-fpm

sudo mkdir -p "$MASTER_INSTALL_ROOT"
sudo git clone "$GIT_REPO" "$MASTER_INSTALL_ROOT"
cd "$MASTER_INSTALL_ROOT"
sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh

sed "s/change_this_db_password/${DB_PASS}/g" db/mysql_setup.example.sql | mysql -u root -p
mysql -u root -p redi_admin < db/redi_admin.sql

export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
sudo -E bash scripts/centos7/grant-node-mysql.sh "5.6.7.8" "$DB_PASS"
sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh "5.6.7.8"

sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload

cd "$MASTER_INSTALL_ROOT/master"
cp .env.example .env
sed -i "s|change_this_db_password|${DB_PASS}|g" .env
sed -i "s|https://your-master-domain.com|http://${MASTER_IP}|g" .env
sed -i 's/ADMIN_HTTPS=true/ADMIN_HTTPS=false/' .env

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
sudo chown -R nginx:nginx storage bootstrap/cache
sudo setsebool -P httpd_can_network_connect 1
sudo chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache
```

`fastcgi_pass` 要和 `grep '^listen' /etc/php-fpm.d/www.conf` 一致。Remi 常见是 `unix:/var/run/php-fpm/www.sock`。站点根目录是 `/opt/301/master/public`。

访问 `http://1.2.3.4/admin`，用 `admin` / `Redi301@Install2026` 登录后马上改密码。

队列单元使用 `User=nginx`、`WorkingDirectory=/opt/301/master`，启动参数是 `queue:work --sleep=3 --timeout=320 --tries=100`。完整单元见 [DEPLOY-centos7.md](DEPLOY-centos7.md) 第 1.4 节。

## 节点 5.6.7.8

主控已经对 `5.6.7.8` 授权并且放行 3306 之后再做。

```bash
export NODE_PUBLIC_IP="5.6.7.8"
export MASTER_IP="1.2.3.4"
export DB_PASS="YourStrongDbPass_2026"

sudo yum install -y tar firewalld
sudo systemctl enable --now firewalld
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-port=80/tcp
sudo firewall-cmd --permanent --add-port=443/tcp
sudo firewall-cmd --reload

sudo mkdir -p /opt/redi-multi
sudo tar xzf /tmp/redi-multi.tar.gz -C /opt/redi-multi
cd /opt/redi-multi/redi-multi

cat > env.sh <<EOF
REDI_DB_HOST=${MASTER_IP}
REDI_DB_PORT=3306
REDI_DB_USER=redi_admin
REDI_DB_PASSWORD=${DB_PASS}
REDI_DB_NAME=redi_admin
REDI_MASTER_URL=http://${MASTER_IP}
REDI_NODE_TOKEN=换成后台创建节点时的Token
EOF
chmod 600 env.sh
sed -i "s/YOUR_NODE_PUBLIC_IP/${NODE_PUBLIC_IP}/g" config.json
sudo setcap cap_net_admin+ep bin/redi-multi
```

把主控上的 `scripts/centos7/redi-multi.service.example` 拷到节点的 `/etc/systemd/system/redi-multi.service` 后：

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now redi-multi
mysql -h 1.2.3.4 -u redi_admin -p'YourStrongDbPass_2026' -e "SELECT 1" redi_admin
```

主控有 HTTPS 域名之后，把 `REDI_MASTER_URL` 改成 `https://主控域名` 并重启 `redi-multi`。`env.sh` 里不要写 `export`。

## 后台里接着做

1. 超级管理员打开「DNS 与节点池」，平台主域名填你控制解析的域名，例如 `123.com`。
2. 「节点管理」新建节点，主公网 IP 填 `5.6.7.8`。Token 写入上面的 `env.sh` 后重启节点。
3. 手动模式：按页面参考表，在 DNS 厂商给 `pool.123.com` 添加 A 记录 `5.6.7.8`，并添加租户 `{token}.123.com` CNAME 到 `pool.123.com`。
4. 「分组管理」新建分组，「域名管理」添加客户域名。把该行的 CNAME 目标交给客户。客户把自己的域名 CNAME 到这个目标，不要 A 到 `5.6.7.8`。
5. 解析预检为「已指向」后，点「申请证书」。

第二台节点重复授权、放行、解压和新建节点。新 IP 要出现在 pool 的 A 记录里。同一台机器的第二个 IP 填在原节点的「其他公网 IP」，不要新建第二条节点。
