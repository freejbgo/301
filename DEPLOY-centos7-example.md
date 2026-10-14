# CentOS 7 填空示例

主控 `1.2.3.4`，第一台节点 `5.6.7.8`。把 IP 和密码换成你的。说明见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。装完后按 [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md) 逐项验收。

仓库放在 `/root/301`，不要克隆到 `/opt/301`。

## 主控上的 vars.env

```bash
cd /root/301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
```

```bash
MASTER_IP="1.2.3.4"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
NODE_IPS="5.6.7.8"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
MASTER_URL="http://1.2.3.4"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="5.6.7.8"
DEFAULT_REDIRECT_URL="https://www.example.com"
REDI_MASTER_URL="http://1.2.3.4"
REDI_NODE_TOKEN=""
```

```bash
sudo bash scripts/centos7/deploy-master.sh
```

浏览器打开 `http://1.2.3.4/admin`，用 `admin` / `Redi301@Install2026` 登录；仍为默认密码时会 **强制改密** 后才能进其它菜单。

在「节点管理」新建节点，主公网 IP 填 `5.6.7.8`，复制 Token。

## 节点 5.6.7.8

把仓库放到节点的 `/root/301`。`vars.env` 与主控相同，只是把 Token 填上：

```bash
REDI_NODE_TOKEN="换成后台创建节点时的Token"
```

```bash
sudo bash scripts/centos7/deploy-node.sh
```

主控有 HTTPS 域名之后，把主控 `.env` 的 `APP_URL` 改成 `https://主控域名`、`ADMIN_HTTPS=true`，节点 `vars.env` 的 `REDI_MASTER_URL` 也改成这个 https 地址，再执行一次 `deploy-node.sh`。

## 后台里接着做

1. 超级管理员打开「DNS 与节点池」，平台主域名填你控制解析的域名，例如 `123.com`。
2. 手动模式：按页面参考表，在 DNS 厂商给 `pool.123.com` 添加 A 记录 `5.6.7.8`，并添加租户 `{token}.123.com` CNAME 到 `pool.123.com`。
3. **超级管理员** 在「分组管理」新建分组（须选 **所属租户账号**）；租户在「域名管理」添加客户域名。把该行的 CNAME 目标交给客户。客户把自己的域名 CNAME 到这个目标，不要 A 到 `5.6.7.8`。
4. 解析预检为「已指向」后，点「申请证书」。

第二台节点：把新 IP 加进主控 `NODE_IPS`，再跑一次 `deploy-master.sh`，然后在新机器上跑 `deploy-node.sh`。同一台机器的第二个 IP 填在原节点的「其他公网 IP」，不要新建第二条节点。
