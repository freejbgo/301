# Ubuntu 填空示例

主控 `1.2.3.4`，节点 `5.6.7.8`。详见 [DEPLOY-ubuntu.md](DEPLOY-ubuntu.md)。

```bash
cd ~/301
cp scripts/ubuntu/vars.example.env scripts/ubuntu/vars.env
chmod 600 scripts/ubuntu/vars.env
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
REDI_MASTER_INSECURE="1"
REDI_MASTER_URL="http://1.2.3.4"
REDI_NODE_TOKEN=""
```

主控：

```bash
sudo bash scripts/ubuntu/deploy-master.sh
```

节点（Token 填好后）：

```bash
sudo bash scripts/ubuntu/deploy-node.sh
```

上 HTTPS 后去掉 `REDI_MASTER_INSECURE`，`MASTER_URL` / `REDI_MASTER_URL` 改为 `https://`，主控 `.env` 同步 `ADMIN_HTTPS=true`，两边脚本各重跑一次。
