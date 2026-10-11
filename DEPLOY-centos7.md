# CentOS 7 部署

系统是 CentOS 7.x。内核小版本可以不同，脚本不按内核编译，也不编译内核模块。

| 机器 | 脚本 | 装好之后 |
|------|------|----------|
| 主控 | `scripts/centos7/deploy-master.sh` | 程序在 `/opt/301/master`，网站根目录 `/opt/301/master/public` |
| 每台节点 | `scripts/centos7/deploy-node.sh` | 程序在 `/opt/redi-multi/redi-multi` |

发布包在 [GitHub Releases](https://github.com/freejbgo/301/releases) 下载。克隆仓库时，同一份文件也在下面这两个路径，部署脚本直接用仓库里的文件：

- `master/master.tar.gz`：PHP 程序，已经包含 `vendor`，没有 `.env`。服务器不用装 Composer。Release 上的文件名是 `master.tar.gz`。
- `node/redi-multi.tar.gz`：静态二进制 `bin/redi-multi`，不依赖 glibc 2.32/2.34，CentOS 7 的 glibc 2.17 可以直接跑。Release 上的文件名是 `redi-multi.tar.gz`。

源码在仓库的 `master/master/`。那是给开发和重新打包用的。自动安装不要把仓库克隆到 `/opt/301`，否则会和发布包解压出来的 `/opt/301/master` 叠在一起。

填好 IP 的示例见 [DEPLOY-centos7-example.md](DEPLOY-centos7-example.md)。后台操作见 [docs/CONSOLE.md](docs/CONSOLE.md)。

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

节点还要填本机的 `NODE_PUBLIC_IP`、`DEFAULT_REDIRECT_URL`、`REDI_MASTER_URL`。Token 等主控后台创建节点后再填 `REDI_NODE_TOKEN`。

## 2. 部署主控

```bash
sudo bash scripts/centos7/deploy-master.sh
```

脚本会：

1. 把已经失效的 CentOS 7 源换成 `vault.centos.org`，EPEL 换成 Fedora 归档。
2. 安装 Remi 的 PHP 8.0、Nginx、firewalld。不安装 PHP 8.1 或更高版本。
3. 安装 MariaDB 10.6。CentOS 7 自带的 5.5 导不进这份 `utf8mb4` 库。
4. 设置 root 密码，关闭反向域名解析，数据库监听 IPv4。3306 不对全网开放，只放行 `NODE_IPS`。
5. 库是空的才导入 `db/redi_admin.sql`。已经有 `admin_users` 时不会覆盖。
6. 把 `master/master.tar.gz` 解压到 `/opt/301/master`，写入 `.env`，执行 `key:generate`、`migrate`、`storage:link`。
7. 配置 PHP-FPM（用户 `nginx`，sock `/var/run/php-fpm/www.sock`）和 Nginx。开启 SELinux 时会放行网站访问网络和数据库。
8. 安装队列 `redi-queue`：`queue:work --sleep=3 --timeout=320 --tries=100`。每天 03:10 以用户 `nginx` 执行 `redi:renew-certificates`。
9. 防火墙开放 22、80、443。

重复执行不会重置已有 `.env`、`APP_KEY` 和业务数据。Nginx 站点文件 `/etc/nginx/conf.d/301-master.conf` 如果已经存在，脚本不覆盖，避免冲掉后来加的 HTTPS。

打开 `http://主控IP/admin`。用户名 `admin`，密码 `Redi301@Install2026`，登录后马上修改。

有域名之后：把 `vars.env` 的 `MASTER_URL` 改成 `https://主控域名`、`ADMIN_HTTPS` 改成 `true`，再改服务器上的 `/opt/301/master/.env`（已有 `.env` 时脚本不会重写它），给 Nginx 配上证书，然后 `systemctl restart php-fpm redi-queue`。这张证书只保护后台。

## 3. 部署节点

先在主控后台「节点管理」新建一条节点，主公网 IP 填这台机器的 IPv4，复制只显示一次的 Token。主控的 `NODE_IPS` 里也要有这个 IP；如果部署主控时还没写，补上后在主控再执行一次 `deploy-master.sh`，它会补授权和防火墙。

把同一份仓库放到节点上（或只把 `redi-multi.tar.gz`、`deploy-node.sh`、`vars.env` 拷过去）。`vars.env` 里填好 `REDI_NODE_TOKEN`。

```bash
sudo bash scripts/centos7/deploy-node.sh
```

脚本会安装 firewalld、iptables、`setcap` 和 MySQL 客户端，尝试加载 `nfnetlink_queue`（编进内核时加载失败可以忽略），解压发布包，写 `env.sh`（没有 `export`），把 `config.json` 的 `server_ip` 和 `default_redirect_url` 改成变量里的值，执行 `setcap cap_net_admin+ep`，并用 systemd 启动。防火墙只开放 22、80、443，不开放 8080。

Token 空着也可以把进程跑起来，但 Agent 不会向主控注册，证书同步也不会做。补上 Token 后再执行一次脚本。

同一台机器的其他公网 IP 填在后台该节点的「其他公网 IP」，不要再部署第二个进程。

自测连库失败时，脚本会停下来。先确认主控已经对这台机器的出口 IP 授权。

## 4. 后台里接着做

1. 「DNS 与节点池」填写平台主域名。
2. 节点状态正常后，手动模式去 DNS 厂商按参考表添加 pool 的 A 和租户 CNAME。API 模式点同步。
3. 「分组管理」建分组，「域名管理」添加客户域名，把 CNAME 目标交给客户。客户 CNAME 到这个目标，不要把域名 A 到某一台节点。
4. 预检为「已指向」后点「申请证书」。

## 5. 以后加一台节点

在主控的 `vars.env` 里把新 IP 加进 `NODE_IPS`，执行 `sudo bash scripts/centos7/deploy-master.sh`。然后在新机器上执行 `deploy-node.sh`。后台新建节点，把 Token 写进新机器的 `vars.env` 再执行一次节点脚本。pool 的 A 记录要包含新 IP。删除节点不会自动删 DNS 记录。

## 6. 重新打包

改了主控依赖或节点源码后，在开发机执行：

```bash
./scripts/build-master-tar.sh
./scripts/build-node-tar.sh
```

把新的 `master/master.tar.gz` 和 `node/redi-multi.tar.gz` 提交。节点打包会拒绝仍然动态链接 libc 的二进制。
