# 小白安装（CentOS 7）

主控只要一条命令，和 CDNFLY 的 `master.sh` 一样。不用填 IP，不用在服务器上编译。安装包已经放在仓库的 `pkg/master.tar.gz` 和 `pkg/redi-multi.tar.gz`。在 **主控** 上用 root 执行：

```bash
curl -fsSL https://github.com/freejbgo/301/raw/main/master.sh -o master.sh && chmod +x master.sh && bash master.sh
```

仓库需要是公开的，这台机器要能访问 GitHub。系统是 **CentOS 7.9**（7.x 也可以），SSH 用 **22** 端口。命令会自己识别公网 IP、下载安装包、装 Nginx / PHP / MariaDB。最后一行是 `主控安装完成`。

装完后：

- 后台：`http://主控公网IP/admin`
- 用户名：`admin`
- 初始密码：`Redi301@Install2026`（登录后页面会要求马上改掉）
- 数据库密码：主控上的 `/root/301-passwords.txt`

云控制台安全组：两台机器都放行 TCP **22 / 80 / 443**。主控的 **3306** 只允许节点公网 IP 来访问。

程序目录和原来的脚本相同：主控 `/opt/301/master`，节点 `/opt/redi-multi/redi-multi`。细节仍见 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md)。

下面从后台建节点开始。节点还要在节点机器上再执行一段命令，因为 Token 只在后台显示一次。

---

## 浏览器里新建节点（拿 Token）

在你自己的电脑上打开浏览器，地址栏输入：

`http://主控公网IP/admin`

例如主控 IP 是 `203.0.113.10`，就打开 `http://203.0.113.10/admin`。

1. 用户名填 `admin`，密码填 `Redi301@Install2026`，登录。
2. 页面要求改密码时，设一个新密码，记下来。新密码不要再用 `Redi301@Install2026`。
3. 左侧打开 **站点配置 → 节点管理**。
4. 点新建。名称随便写，例如 `node-1`。**主公网 IP** 填节点那台机器的公网 IP。启用打开。保存。
5. 弹出的 **Token** 只显示这一次。立刻复制到记事本。关掉窗口就看不到了。丢了就在这一行上做「轮换」，用新 Token，旧的同时失效。

Token 是一长串字母和数字，中间没有空格。

---

## 安装节点（只在节点做）

登录 **节点**（不是主控）。改下面三行，再整段粘贴。

- `MASTER_IP`：主控公网 IP
- `NODE_IP`：这台节点自己的公网 IP
- `REDI_NODE_TOKEN`：上面复制的 Token

粘贴后如果出现 `password:` 或 `root@主控IP's password:`，输入 **主控的 root 密码**。输入时屏幕不显示字符，输完回车。这是节点在向主控拷贝安装包和数据库密码。节点不用再访问 GitHub。

成功标志：`节点安装完成`，并且 `redi-multi` 是 `active`。中途出现 `警告: REDI_MASTER_INSECURE=1` 是正常的，第一次用 IP 访问主控就会这样。

一次粘贴整段（从 `cat` 到最后一行 `bash /root/redi-step4-node.sh`）。出现密码提问时，输入主控的 root 密码。

```bash
cat > /root/redi-step4-node.sh <<'ENDSCRIPT'
set -euo pipefail

# ===== 只改这里 =====
MASTER_IP="填主控公网IP"
NODE_IP="填这台节点的公网IP"
REDI_NODE_TOKEN="把后台复制的Token贴在这里"
# ===== 只改这里 =====

if [[ "$(id -u)" -ne 0 ]]; then
  echo "错误: 请用 root 登录后再粘贴"
  exit 1
fi
if [[ ! -f /etc/os-release ]]; then
  echo "错误: 找不到 /etc/os-release"
  exit 1
fi
# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "centos" || "${VERSION_ID:-}" != 7* ]]; then
  echo "错误: 这个脚本只用于 CentOS 7.x，当前是 ${ID:-unknown} ${VERSION_ID:-unknown}"
  exit 1
fi
valid_ip() {
  local ip="$1" o a b c d
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}
is_private() {
  case "$1" in
    10.*|127.*|0.*|169.254.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
    *) return 1 ;;
  esac
}
if [[ "$MASTER_IP" == "填主控公网IP" || "$NODE_IP" == "填这台节点的公网IP" ]]; then
  echo "错误: 请先把 MASTER_IP 和 NODE_IP 改成公网 IP"
  exit 1
fi
valid_ip "$MASTER_IP" || { echo "错误: MASTER_IP 不是 IPv4: $MASTER_IP"; exit 1; }
valid_ip "$NODE_IP" || { echo "错误: NODE_IP 不是 IPv4: $NODE_IP"; exit 1; }
if is_private "$MASTER_IP" || is_private "$NODE_IP"; then
  echo "错误: 填到了内网 IP。请填云控制台里的公网 IP"
  exit 1
fi
if [[ "$MASTER_IP" == "$NODE_IP" ]]; then
  echo "错误: 主控和节点要两台机器，两个 IP 不能相同"
  exit 1
fi
if [[ "$REDI_NODE_TOKEN" == "把后台复制的Token贴在这里" || -z "$REDI_NODE_TOKEN" ]]; then
  echo "错误: 请把 REDI_NODE_TOKEN 换成后台复制的 Token"
  exit 1
fi
if [[ "$REDI_NODE_TOKEN" =~ [[:space:]\'\"\`\#\$\\] ]]; then
  echo "错误: Token 里不要有空格、引号或 #。请重新复制整串 Token"
  exit 1
fi
_TYPED_MASTER="$MASTER_IP"
_TYPED_NODE="$NODE_IP"
_TYPED_TOKEN="$REDI_NODE_TOKEN"

echo "==> 换成可用的 CentOS 7 软件源"
if [[ -f /etc/yum/pluginconf.d/fastestmirror.conf ]]; then
  sed -i 's/^enabled=1/enabled=0/' /etc/yum/pluginconf.d/fastestmirror.conf
fi
if grep -q '^ip_resolve=' /etc/yum.conf 2>/dev/null; then
  sed -i 's/^ip_resolve=.*/ip_resolve=4/' /etc/yum.conf
else
  echo 'ip_resolve=4' >> /etc/yum.conf
fi
shopt -s nullglob
for f in /etc/yum.repos.d/CentOS-*.repo; do
  sed -i \
    -e 's/^mirrorlist=/#mirrorlist=/' \
    -e 's/^# *baseurl=/baseurl=/' \
    -e 's|http://mirror.centos.org/centos|https://vault.centos.org/centos|g' \
    -e 's|https://mirror.centos.org/centos|https://vault.centos.org/centos|g' \
    "$f"
done
shopt -u nullglob
if ! grep -Rqs 'vault.centos.org' /etc/yum.repos.d/CentOS-*.repo 2>/dev/null; then
  cat > /etc/yum.repos.d/CentOS-Base.repo <<'EOF'
[base]
name=CentOS-7 - Base
baseurl=https://vault.centos.org/centos/7/os/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1

[updates]
name=CentOS-7 - Updates
baseurl=https://vault.centos.org/centos/7/updates/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1

[extras]
name=CentOS-7 - Extras
baseurl=https://vault.centos.org/centos/7/extras/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1
EOF
fi
mkdir -p /etc/yum.repos.d/disabled-by-redi
shopt -s nullglob
for f in /etc/yum.repos.d/CentOS-*.repo; do
  if ! grep -q 'vault.centos.org' "$f"; then
    mv "$f" /etc/yum.repos.d/disabled-by-redi/
  fi
done
shopt -u nullglob
yum clean all
yum install -y openssh-clients

echo "==> 从主控拷贝安装脚本、安装包和数据库密码。出现 password 时输入主控 root 密码（输入不显示）"
mkdir -p /root/.ssh /tmp/from-master
chmod 700 /root/.ssh
rm -rf /tmp/from-master
mkdir -p /tmp/from-master
ssh-keyscan -4 -T 15 "$MASTER_IP" >> /root/.ssh/known_hosts 2>/dev/null || true
scp -r \
  "root@${MASTER_IP}:/root/301" \
  "root@${MASTER_IP}:/tmp/redi-multi.tar.gz" \
  "root@${MASTER_IP}:/root/301-for-node.txt" \
  /tmp/from-master/
rm -rf /root/301
mv /tmp/from-master/301 /root/301
mv /tmp/from-master/redi-multi.tar.gz /tmp/redi-multi.tar.gz
mv /tmp/from-master/301-for-node.txt /root/301-for-node.txt
rm -rf /tmp/from-master
chmod 600 /root/301-for-node.txt /tmp/redi-multi.tar.gz
if [[ ! -f /root/301/scripts/centos7/deploy-node.sh ]]; then
  echo "错误: 主控上还没有安装脚本。请先在主控执行 master.sh"
  exit 1
fi
# shellcheck disable=SC1091
source /root/301-for-node.txt
MASTER_IP="$_TYPED_MASTER"
NODE_IP="$_TYPED_NODE"
REDI_NODE_TOKEN="$_TYPED_TOKEN"
if [[ -z "${DB_PASS:-}" || -z "${DEFAULT_REDIRECT_URL:-}" ]]; then
  echo "错误: 主控上的 /root/301-for-node.txt 不完整。请在主控再执行一次 master.sh"
  exit 1
fi

cat > /root/301/scripts/centos7/vars.env <<EOF
MASTER_IP="${MASTER_IP}"
DB_PASS="${DB_PASS}"
NODE_IPS="${NODE_IP}"
REDI_MASTER_TAR="/tmp/master.tar.gz"
REDI_NODE_TAR="/tmp/redi-multi.tar.gz"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
MASTER_URL="http://${MASTER_IP}"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="${NODE_IP}"
DEFAULT_REDIRECT_URL="${DEFAULT_REDIRECT_URL}"
REDI_MASTER_URL="http://${MASTER_IP}"
REDI_MASTER_INSECURE="1"
REDI_DB_TLS=""
REDI_MIN_AGENT_VERSION="0.1.3"
REDI_ALLOW_TRUST_ALL_PROXIES="false"
REDI_NODE_TOKEN="${REDI_NODE_TOKEN}"
EOF
chmod 600 /root/301/scripts/centos7/vars.env

cd /root/301
bash scripts/centos7/deploy-node.sh

echo
echo "======== 节点安装完成 ========"
systemctl is-active redi-multi
echo "回到主控后台「站点配置 → 节点管理」，刷新后这一行应出现心跳时间。"
ENDSCRIPT
chmod 700 /root/redi-step4-node.sh
bash /root/redi-step4-node.sh
```

---

## 怎样算装好

在主控上粘贴：

```bash
systemctl is-active nginx php-fpm mariadb redi-queue
cd /opt/301/master && sudo -u nginx php artisan redi:health
```

四行服务都是 `active`。`redi:health` 能跑完即可，这时还没有客户域名，有些检查项可以先不管。

在节点上粘贴：

```bash
systemctl is-active redi-multi
getcap /opt/redi-multi/redi-multi/bin/redi-multi
```

第一行是 `active`。第二行里有 `cap_net_admin`。

后台 **站点配置 → 节点管理** 里，这台节点的「最后心跳」会更新。没有心跳时，看 Token 是否整串贴对，以及云安全组是否放行了节点访问主控的 **3306**。

---

## 装好之后（给客户域名用之前）

现在后台可以用 IP 打开，节点也已经连上主控。客户域名还要在后台做这些事（点哪里见 [CONSOLE.md](CONSOLE.md)）：

1. **站点配置 → DNS 与节点池**：填写平台主域名。
2. 按页面上的表，到你的 DNS 服务商给 `pool` 加 **A 记录**，值是节点公网 IP。有多台节点就加多条 A。
3. **站点配置 → 分组管理**：新建一个分组。
4. **站点配置 → 域名管理**：添加客户域名，把页面上的 **CNAME 目标** 发给客户。客户把域名 CNAME 到这个目标。
5. 预检显示已指向后，点 **申请证书**。

客户不要把域名 A 到某一台节点的 IP。说明可直接转给客户：[CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md)。

对外给客户用之前，主控要改成 **https 域名**（节点访问主控也改成 `https://`）。第一次安装为了能先打开后台，用的是 `http://IP`。改法见 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) 步骤 6 最后一段，以及 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 第 2 节末尾。上线前打勾用 [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md)。

---

## 再加一台节点

1. 新机器也是 CentOS 7，安全组放行 22、80、443。主控安全组的 3306 再加这个新公网 IP。
2. 在 **主控** 上改 `NEW_NODE_IP` 后整段粘贴（会把新 IP 加入允许连数据库的名单）：

```bash
cat > /root/redi-step-add-node.sh <<'ENDSCRIPT'
set -euo pipefail
# ===== 只改这里 =====
NEW_NODE_IP="填新节点公网IP"
# ===== 只改这里 =====
if [[ "$(id -u)" -ne 0 ]]; then
  echo "错误: 请用 root 登录后再粘贴"
  exit 1
fi
valid_ip() {
  local ip="$1" o a b c d
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}
[[ "$NEW_NODE_IP" != "填新节点公网IP" ]] || { echo "错误: 请先填写新节点公网 IP"; exit 1; }
valid_ip "$NEW_NODE_IP" || { echo "错误: 不是 IPv4: $NEW_NODE_IP"; exit 1; }
cd /root/301
ip_re="${NEW_NODE_IP//./\\.}"
if grep -E '^NODE_IPS=' scripts/centos7/vars.env | grep -Eq "(^|[^0-9])${ip_re}([^0-9]|$)"; then
  echo "vars.env 里已经有 $NEW_NODE_IP"
else
  sed -i "s/^NODE_IPS=\"/NODE_IPS=\"${NEW_NODE_IP} /" scripts/centos7/vars.env
fi
bash scripts/centos7/grant-nodes-from-vars.sh
echo "主控已允许 ${NEW_NODE_IP} 连接数据库"
ENDSCRIPT
chmod 700 /root/redi-step-add-node.sh
bash /root/redi-step-add-node.sh
```

3. 后台 **节点管理** 再新建一条，主公网 IP 填这台新机器，复制新的 Token。
4. 在 **新节点** 上再执行下面的「安装节点」整段。`MASTER_IP` 仍是主控 IP，`NODE_IP` 填这台新机器的公网 IP，`REDI_NODE_TOKEN` 用这条新 Token。
5. DNS 里 `pool` 的 A 记录加上这个新 IP。

同一台机器上的第二个公网 IP，填在后台这一条节点的「其他公网 IP」里，不要再装一遍程序。

---

## 出错了

| 屏幕上的字 | 处理 |
|------------|------|
| `请先把 MASTER_IP` / `填主控公网IP` | 代码块里的 IP 还是说明文字。改成数字 IP 后再整段粘贴 |
| `这个脚本只用于 CentOS 7` | 系统不是 CentOS 7。重装成 CentOS 7.9 后再做 |
| `下载代码失败` 或 `仓库里没有安装包` | 先把仓库设为公开，主控要能访问 github.com，然后重新执行上面的一条命令 |
| `没能自动识别公网 IP` | 再执行一次：`MASTER_IP=主控公网IP bash master.sh` |
| `磁盘剩余不足` | 系统盘扩容到至少 20GB |
| `需要 PHP 8.0` | 把这一行上面的 yum 报错保留，在主控重新执行 master.sh |
| `连不上` 且提到 `redi_node` 或 `3306` | 云安全组：主控的 3306 来源要包含这台节点的公网 IP。在主控按下面「再加一台节点」把这个 IP 放行，再到节点重做安装节点那段 |
| `redi-multi 没有保持运行` | 节点上 80 或 443 已被别的网站程序占用。停掉占用的程序后，再粘贴一次安装节点那段 |
| `主控上还没有安装脚本` | 先在主控把 master.sh 跑完，再在节点粘贴安装节点那段 |
| `password:` 一直失败 | 输入的是主控 root 密码。主控若关闭了密码登录，要先允许 root 用密码从节点 SSH 过来，或把节点公钥写进主控 `/root/.ssh/authorized_keys` |
| 后台打不开 | 云安全组和系统防火墙都要放行 80。地址是 `http://` 加主控公网 IP 加 `/admin` |
| 节点列表没有心跳 | Token 要整串重新贴进安装节点那段再执行一次。主控地址是 `http://主控IP` |

主控命令中途失败时，修好之后再执行一次同一条命令即可，不必重装系统。

数据库 root 密码和程序用的数据库密码在主控 `/root/301-passwords.txt`。这个文件只留在服务器上，不要发到聊天软件里。
