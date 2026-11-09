# CentOS 7 填空示例

主控 `1.2.3.4`，第一台节点 `5.6.7.8`。把 IP 和密码换成你的。

- **分步教程（推荐先看）：** [docs/DEPLOY-TUTORIAL-CENTOS7.md](docs/DEPLOY-TUTORIAL-CENTOS7.md)
- **装完后验收：** [docs/ACCEPTANCE-CENTOS7-DEPLOY.md](docs/ACCEPTANCE-CENTOS7-DEPLOY.md)

仓库放在 **`/root/301`**，不要克隆到 `/opt/301`。

---

## A. 发版机（打 tar，可用任意 Linux）

```bash
cd /path/to/301
bash scripts/dev-preflight.sh
bash scripts/build-release-tarballs.sh
scp master/master.tar.gz node/redi-multi.tar.gz root@1.2.3.4:/tmp/
scp node/redi-multi.tar.gz root@5.6.7.8:/tmp/
```

---

## B. 主控 1.2.3.4

```bash
yum install -y git
git clone https://github.com/freejbgo/301.git /root/301
cd /root/301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
```

`vars.env` 核心字段示例：

```bash
MASTER_IP="1.2.3.4"
DB_PASS="YourStrongDbPass_2026"
MYSQL_ROOT_PASS="YourMysqlRootPass"
NODE_IPS="5.6.7.8"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
REDI_MASTER_TAR="/tmp/master.tar.gz"
REDI_NODE_TAR=""
MASTER_URL="http://1.2.3.4"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="5.6.7.8"
DEFAULT_REDIRECT_URL="https://www.example.com"
REDI_MASTER_INSECURE="1"
REDI_MASTER_URL="http://1.2.3.4"
REDI_NODE_TOKEN=""
```

**联调专用：** 上表 `MASTER_URL` / `REDI_MASTER_URL` 为 `http://` 且 `REDI_MASTER_INSECURE=1` 仅用于主控尚未签 HTTPS 证书时。**对外上线前**须改 `https://`、`ADMIN_HTTPS=true`、去掉 `REDI_MASTER_INSECURE`，并同步节点 `env.sh`（见 [SECURITY.md](SECURITY.md)）。

```bash
sudo bash scripts/centos7/deploy-master.sh
```

浏览器：`http://1.2.3.4/admin` → `admin` / `Redi301@Install2026` → **强制改密**。

在「节点管理」新建节点，主公网 IP `5.6.7.8`，复制 **Token**。

---

## C. 节点 5.6.7.8

```bash
yum install -y git
git clone https://github.com/freejbgo/301.git /root/301
cd /root/301
cp scripts/centos7/vars.example.env scripts/centos7/vars.env
chmod 600 scripts/centos7/vars.env
```

与主控相同的密码与 `MASTER_IP` 等，并设置：

```bash
NODE_PUBLIC_IP="5.6.7.8"
REDI_NODE_TAR="/tmp/redi-multi.tar.gz"
REDI_NODE_TOKEN="换成后台创建节点时的Token"
```

```bash
sudo bash scripts/centos7/deploy-node.sh
```

主控有 HTTPS 域名之后：删掉 `REDI_MASTER_INSECURE`，主控 `.env` 的 `APP_URL` 改为 `https://…`，节点 `REDI_MASTER_URL` 改为同一 **https://**，再执行一次 `deploy-node.sh`。

---

## D. 后台里接着做

1. 超级管理员打开「DNS 与节点池」，平台主域名填你控制解析的域名，例如 `123.com`。
2. 手动模式：按页面参考表，在 DNS 厂商给 `pool.123.com` 添加 A 记录 `5.6.7.8`，并添加租户 `{token}.123.com` CNAME 到 `pool.123.com`。
3. **超级管理员** 在「分组管理」新建分组（须选 **所属租户账号**）；租户在「域名管理」添加客户域名。把该行的 CNAME 目标交给客户。
4. 解析预检为「已指向」后，点「申请证书」。

第二台节点：把新 IP 加进主控 `NODE_IPS`，再跑一次 `deploy-master.sh`，然后在新机器上重复 **C** 节。同一台机器的第二个 IP 填在原节点的「其他公网 IP」，不要新建第二条节点。

详细说明见 [DEPLOY-centos7.md](DEPLOY-centos7.md)。
