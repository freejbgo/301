# 小白复制粘贴安装（CentOS 7）

按顺序做。每一段灰色命令都是 **整段复制、一次粘贴、回车**。改字只改写着「只改这里」的那几行，改完再整段粘贴。

装完的程序和 [DEPLOY-TUTORIAL-CENTOS7.md](DEPLOY-TUTORIAL-CENTOS7.md) 相同：主控在 `/opt/301/master`，节点在 `/opt/redi-multi/redi-multi`。数据库由 `deploy-master.sh` 导入 `db/redi_admin.sql`。更细的说明仍以那份教程和 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 为准。

---

## 先准备这些

| 要有的 | 说明 |
|--------|------|
| 两台服务器 | 都是 **CentOS 7.9**（7.x 也可以）。一台当主控，一台当节点 |
| root | 用 root 登录。下面的命令都在 root 下执行 |
| SSH 端口 | 用 **22** 登录。安装时会打开系统防火墙并放行 22、80、443 |
| 两个公网 IP | 到买服务器的网站上看「公网 IP」，记在记事本里。形如 `203.0.113.10`，是一串数字 |
| GitHub 令牌 | 代码仓库不是公开的。浏览器打开 GitHub → 右上角头像 → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)** → **Generate new token** → 勾选 **repo** → 生成后复制。令牌只显示一次 |
| 内存 | 主控建议 2GB。只有 1GB 时，第 1 步会自动加一块 2GB 的磁盘当内存用 |

密码不用自己编。第 2 步会生成数据库密码，写在主控的 `/root/301-passwords.txt`。

后台登录密码是另一套，和数据库密码无关：

- 地址：`http://主控公网IP/admin`
- 用户名：`admin`
- 初始密码：`Redi301@Install2026`（登录后页面会要求马上改掉）

---

## 云控制台安全组（两台都要做）

这是买服务器的网页里的防火墙，和后面命令打开的系统防火墙是两件事，**两个都要放行**。

两台都放行：

- TCP **22**（SSH）
- TCP **80**
- TCP **443**

主控再加一条：TCP **3306**，来源只填 **节点的公网 IP**。3306 用来给节点连数据库。来源填 `0.0.0.0/0` 会把数据库暴露到全网，不要这样填。

---

## 怎么粘贴

1. 用 Xshell、FinalShell、或系统终端，root 登录对应的那台机器。
2. 打开下面某一个代码块，点复制。
3. 代码块里如果有「只改这里」，先把那几行改成你的 IP 或 Token，再复制 **从第一行到最后一行**。
4. 在黑窗口里粘贴（Xshell / FinalShell 一般是右键），回车。
5. 等到出现文中写的那句「成功标志」，再做下一步。中间会刷很多字，属于正常。看到 `错误:` 就停下来，对照文末「出错了」。

---

## 第 1 步：在主控上打包（只在主控做）

登录 **主控**。先把脚本开头的 `GITHUB_TOKEN` 换成刚才复制的令牌，再整段粘贴。这一步会换系统源、安装 PHP 和 Go、下载代码、打出两个安装包。一般 **10 到 30 分钟**。成功标志是最后一行：`打包完成`。成功后带令牌的脚本文件会删掉。

一次粘贴整段（从 `cat` 那一行到最后的 `bash /root/redi-step1-build.sh`）：

```bash
cat > /root/redi-step1-build.sh <<'ENDSCRIPT'
set -euo pipefail

# ===== 只改这里 =====
# 用户名一般不用改。GITHUB_TOKEN 换成上一节生成的令牌，整串粘贴，不要加空格或引号。
GITHUB_USER="freejbgo"
GITHUB_TOKEN="填GitHub令牌"
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
if [[ "${GITHUB_TOKEN}" == "填GitHub令牌" || -z "${GITHUB_TOKEN}" ]]; then
  echo "错误: 请先把 GITHUB_TOKEN 换成 GitHub 个人访问令牌（勾选 repo）"
  exit 1
fi
if [[ "${GITHUB_USER}" =~ [[:space:]\'\"\`\#\$\\:/@] || "${GITHUB_TOKEN}" =~ [[:space:]\'\"\`\#\$\\] ]]; then
  echo "错误: 用户名或令牌里不要有空格、引号、# 或 $。请重新复制令牌"
  exit 1
fi

avail_kb="$(df -Pk / | awk 'NR==2 {print $4}')"
if [[ "${avail_kb:-0}" -lt 5000000 ]]; then
  echo "错误: 磁盘剩余不足 5GB。请把系统盘扩到至少 20GB 后再装"
  exit 1
fi

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
yum makecache

mem_kb="$(awk '/MemTotal/{print $2}' /proc/meminfo)"
if [[ "${mem_kb:-0}" -lt 1800000 ]]; then
  echo "==> 内存小于 2GB，添加 2GB 交换空间，避免打包时内存不够"
  if [[ ! -f /swapfile ]]; then
    dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
  fi
  swapon -s | grep -q /swapfile || swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> 安装 git、PHP 8.0、编译工具"
yum install -y epel-release yum-utils
if compgen -G "/etc/yum.repos.d/epel*.repo" > /dev/null; then
  sed -i \
    -e 's/^metalink=/#metalink=/' \
    -e 's/^mirrorlist=/#mirrorlist=/' \
    -e 's|^# *baseurl=.*epel/|baseurl=https://archives.fedoraproject.org/pub/archive/epel/|' \
    /etc/yum.repos.d/epel*.repo
fi
if ! rpm -q remi-release >/dev/null 2>&1; then
  yum install -y "https://rpms.remirepo.net/enterprise/remi-release-7.rpm"
fi
if compgen -G "/etc/yum.repos.d/epel*.repo" > /dev/null; then
  sed -i \
    -e 's/^metalink=/#metalink=/' \
    -e 's/^mirrorlist=/#mirrorlist=/' \
    -e 's|^# *baseurl=.*epel/|baseurl=https://archives.fedoraproject.org/pub/archive/epel/|' \
    /etc/yum.repos.d/epel*.repo
fi
yum-config-manager --enable remi-php80
yum install -y git curl tar unzip openssl file \
  php php-cli php-fpm php-mysqlnd php-mbstring php-xml php-pecl-zip \
  php-gd php-bcmath php-opcache php-process
php -r 'if (PHP_VERSION_ID < 80000 || PHP_VERSION_ID >= 80100) { fwrite(STDERR, "需要 PHP 8.0，当前 ".PHP_VERSION."\n"); exit(1); }'
echo "PHP $(php -r 'echo PHP_VERSION;')"

echo "==> 安装 Go 1.21.13"
export PATH="/usr/local/go/bin:/usr/local/bin:${PATH}"
if ! go version 2>/dev/null | grep -q 'go1.21.13 '; then
  curl -fL --retry 3 -o /tmp/go1.21.13.linux-amd64.tar.gz https://go.dev/dl/go1.21.13.linux-amd64.tar.gz
  rm -rf /usr/local/go
  tar -C /usr/local -xzf /tmp/go1.21.13.linux-amd64.tar.gz
fi
go version

echo "==> 安装 Composer 2.2（配合 PHP 8.0）"
if [[ ! -x /usr/local/bin/composer ]]; then
  curl -fL --retry 3 -o /usr/local/bin/composer https://getcomposer.org/download/2.2.25/composer.phar
  chmod +x /usr/local/bin/composer
fi
export COMPOSER_ALLOW_SUPERUSER=1
composer --version

echo "==> 下载项目到 /root/301"
if [[ ! -f /root/301/scripts/build-release-tarballs.sh ]]; then
  rm -rf /root/301
  askpass="$(mktemp)"
  cat > "$askpass" <<EOF
#!/bin/sh
case "\$1" in
  *Username*) printf '%s\n' "${GITHUB_USER}" ;;
  *) printf '%s\n' "${GITHUB_TOKEN}" ;;
esac
EOF
  chmod 700 "$askpass"
  export GIT_ASKPASS="$askpass"
  export GIT_TERMINAL_PROMPT=0
  if ! git clone --depth 1 https://github.com/freejbgo/301.git /root/301; then
    rm -f "$askpass"
    rm -rf /root/301
    echo "错误: 下载代码失败。请确认令牌勾了 repo，并且主控能访问 github.com"
    exit 1
  fi
  rm -f "$askpass"
  unset GIT_ASKPASS
fi

echo "==> 安装主控依赖并打两个包"
cd /root/301/master/master
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
cd /root/301
bash scripts/build-release-tarballs.sh
cp -f /root/301/master/master.tar.gz /tmp/master.tar.gz
cp -f /root/301/node/redi-multi.tar.gz /tmp/redi-multi.tar.gz
ls -lh /tmp/master.tar.gz /tmp/redi-multi.tar.gz
echo "打包完成"
ENDSCRIPT
chmod 700 /root/redi-step1-build.sh
bash /root/redi-step1-build.sh && rm -f /root/redi-step1-build.sh
```

下载如果停在 `go: downloading` 或 `composer` 超过 10 分钟没有新输出，按 `Ctrl+C`，再整段粘贴下面这一段，然后 **再粘贴一次第 1 步**：

```bash
export GOPROXY="https://goproxy.cn,direct"
export GO111MODULE=on
export COMPOSER_ALLOW_SUPERUSER=1
composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
```

---

## 第 2 步：安装主控（只在主控做）

仍在 **主控**。先改下面代码里的两行 IP，再整段粘贴。

- `MASTER_IP`：主控公网 IP
- `NODE_IP`：节点公网 IP
- `DEFAULT_REDIRECT_URL`：访客域名还没配好时，节点把人转到这个网址。可以先留着 `https://www.example.com`，以后再改

成功标志：`主控安装完成`，以及 `nginx`、`php-fpm`、`mariadb`、`redi-queue` 四个都是 `active`。

一次粘贴整段（从 `cat` 到最后一行 `bash /root/redi-step2-master.sh`）：

```bash
cat > /root/redi-step2-master.sh <<'ENDSCRIPT'
set -euo pipefail

# ===== 只改这里 =====
MASTER_IP="填主控公网IP"
NODE_IP="填节点公网IP"
DEFAULT_REDIRECT_URL="https://www.example.com"
# ===== 只改这里 =====

if [[ "$(id -u)" -ne 0 ]]; then
  echo "错误: 请用 root 登录后再粘贴"
  exit 1
fi
valid_ip() {
  local ip="$1" o
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  local a b c d
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
if [[ "$MASTER_IP" == "填主控公网IP" || "$NODE_IP" == "填节点公网IP" ]]; then
  echo "错误: 请先把 MASTER_IP 和 NODE_IP 改成公网 IP，再粘贴"
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
if [[ "$DEFAULT_REDIRECT_URL" != http://* && "$DEFAULT_REDIRECT_URL" != https://* ]]; then
  echo "错误: DEFAULT_REDIRECT_URL 要以 http:// 或 https:// 开头"
  exit 1
fi
if [[ "$DEFAULT_REDIRECT_URL" =~ [[:space:]\'\"\`\#\$\\] ]]; then
  echo "错误: 跳转网址不要包含空格、引号、#、$ 或反斜杠"
  exit 1
fi
if [[ ! -f /root/301/scripts/centos7/deploy-master.sh ]]; then
  echo "错误: 还没有 /root/301。请先做第 1 步"
  exit 1
fi
if [[ ! -f /tmp/master.tar.gz || ! -f /tmp/redi-multi.tar.gz ]]; then
  echo "错误: 未找到主控发布包。请先做第 1 步，直到出现「打包完成」"
  exit 1
fi

_MASTER_IP="$MASTER_IP"
_NODE_IP="$NODE_IP"
_URL="$DEFAULT_REDIRECT_URL"
if [[ -f /root/301-passwords.txt ]]; then
  # shellcheck disable=SC1091
  source /root/301-passwords.txt
fi
MASTER_IP="$_MASTER_IP"
NODE_IP="$_NODE_IP"
DEFAULT_REDIRECT_URL="$_URL"
if [[ -z "${DB_PASS:-}" || -z "${MYSQL_ROOT_PASS:-}" ]]; then
  DB_PASS="$(openssl rand -hex 16)"
  MYSQL_ROOT_PASS="$(openssl rand -hex 16)"
fi

umask 077
cat > /root/301-passwords.txt <<EOF
MASTER_IP=${MASTER_IP}
NODE_IP=${NODE_IP}
DB_PASS=${DB_PASS}
MYSQL_ROOT_PASS=${MYSQL_ROOT_PASS}
DEFAULT_REDIRECT_URL=${DEFAULT_REDIRECT_URL}
EOF
cat > /root/301-for-node.txt <<EOF
MASTER_IP=${MASTER_IP}
NODE_IP=${NODE_IP}
DB_PASS=${DB_PASS}
DEFAULT_REDIRECT_URL=${DEFAULT_REDIRECT_URL}
EOF
chmod 600 /root/301-passwords.txt /root/301-for-node.txt

NODE_IPS="${NODE_IP}"
if [[ -f /root/301/scripts/centos7/vars.env ]]; then
  old_ips="$(sed -n 's/^NODE_IPS="\([^"]*\)".*/\1/p' /root/301/scripts/centos7/vars.env | head -n 1)"
  for ip in ${old_ips:-}; do
    [[ -z "$ip" || "$ip" == "$NODE_IP" ]] && continue
    case " ${NODE_IPS} " in
      *" ${ip} "*) ;;
      *) NODE_IPS="${NODE_IPS} ${ip}" ;;
    esac
  done
fi

cat > /root/301/scripts/centos7/vars.env <<EOF
MASTER_IP="${MASTER_IP}"
DB_PASS="${DB_PASS}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS}"
NODE_IPS="${NODE_IPS}"
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
REDI_NODE_TOKEN=""
EOF
chmod 600 /root/301/scripts/centos7/vars.env

cd /root/301
bash scripts/centos7/deploy-master.sh

echo
echo "======== 主控安装完成 ========"
systemctl is-active nginx php-fpm mariadb redi-queue
echo "后台地址: http://${MASTER_IP}/admin"
echo "用户名: admin"
echo "初始密码: Redi301@Install2026"
echo "登录后按页面要求改掉这个密码，并把新密码记在记事本里。"
echo "数据库密码在 /root/301-passwords.txt（给节点用，不用拿去登录后台）。"
echo "下一步：用浏览器打开后台，新建节点，复制 Token。"
ENDSCRIPT
chmod 700 /root/redi-step2-master.sh
bash /root/redi-step2-master.sh
```

第 2 步可以重复粘贴。数据库密码只在第一次生成，再跑不会换一套新密码。已经加过的节点 IP 会保留在允许连数据库的名单里。

---

## 第 3 步：浏览器里新建节点（拿 Token）

在你自己的电脑上打开浏览器，地址栏输入：

`http://主控公网IP/admin`

例如主控 IP 是 `203.0.113.10`，就打开 `http://203.0.113.10/admin`。

1. 用户名填 `admin`，密码填 `Redi301@Install2026`，登录。
2. 页面要求改密码时，设一个新密码，记下来。新密码不要再用 `Redi301@Install2026`。
3. 左侧打开 **站点配置 → 节点管理**。
4. 点新建。名称随便写，例如 `node-1`。**主公网 IP** 填节点那台机器的公网 IP（和第 2 步的 `NODE_IP` 相同）。启用打开。保存。
5. 弹出的 **Token** 只显示这一次。立刻复制到记事本。关掉窗口就看不到了。丢了就在这一行上做「轮换」，用新 Token，旧的同时失效。

Token 是一长串字母和数字，中间没有空格。

---

## 第 4 步：安装节点（只在节点做）

登录 **节点**（不是主控）。改下面三行，再整段粘贴。

- `MASTER_IP`：主控公网 IP
- `NODE_IP`：这台节点自己的公网 IP
- `REDI_NODE_TOKEN`：第 3 步复制的 Token

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
  echo "错误: 主控上还没有安装脚本。请确认主控已经做完第 1 步"
  exit 1
fi
# shellcheck disable=SC1091
source /root/301-for-node.txt
MASTER_IP="$_TYPED_MASTER"
NODE_IP="$_TYPED_NODE"
REDI_NODE_TOKEN="$_TYPED_TOKEN"
if [[ -z "${DB_PASS:-}" || -z "${DEFAULT_REDIRECT_URL:-}" ]]; then
  echo "错误: 主控上的 /root/301-for-node.txt 不完整。请在主控再执行一次第 2 步"
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
4. 在 **新节点** 上做第 4 步。`MASTER_IP` 仍是主控 IP，`NODE_IP` 填这台新机器的公网 IP，`REDI_NODE_TOKEN` 用这条新 Token。
5. DNS 里 `pool` 的 A 记录加上这个新 IP。

同一台机器上的第二个公网 IP，填在后台这一条节点的「其他公网 IP」里，不要再装一遍程序。

---

## 出错了

| 屏幕上的字 | 处理 |
|------------|------|
| `请先把 MASTER_IP` / `填主控公网IP` | 代码块里的 IP 还是说明文字。改成数字 IP 后再整段粘贴 |
| `这个脚本只用于 CentOS 7` | 系统不是 CentOS 7。重装成 CentOS 7.9 后再做 |
| `未找到主控发布包` 或没有「打包完成」 | 在主控重做第 1 步，等到 `打包完成` |
| `磁盘剩余不足` | 系统盘扩容到至少 20GB |
| `需要 PHP 8.0` | 第 1 步的 PHP 没装上。把该行上面的 yum 报错保留，重做第 1 步 |
| `连不上` 且提到 `redi_node` 或 `3306` | 云安全组：主控的 3306 来源要包含这台节点的公网 IP。第 2 步的 `NODE_IP` 必须是节点公网 IP。改完后在主控再粘贴一次第 2 步，然后在节点再粘贴一次第 4 步 |
| `redi-multi 没有保持运行` | 节点上 80 或 443 已被别的网站程序占用。停掉占用的程序后再粘贴第 4 步 |
| `下载代码失败` 或 `GITHUB_TOKEN` | 令牌要勾选 repo。主控要能打开 github.com。改好令牌后整段再贴第 1 步 |
| `主控上还没有安装脚本` | 先在主控做完第 1 步和第 2 步，再在节点贴第 4 步 |
| `password:` 一直失败 | 输入的是主控 root 密码。主控若关闭了密码登录，要先允许 root 用密码从节点 SSH 过来，或把节点公钥写进主控 `/root/.ssh/authorized_keys` |
| 后台打不开 | 云安全组和系统防火墙都要放行 80。地址是 `http://` 加主控公网 IP 加 `/admin` |
| 节点列表没有心跳 | Token 要整串重新贴进第 4 步再执行一次。主控地址在第一次安装时是 `http://主控IP` |

第 1 步或第 2 步中途失败时，改完问题后 **整段再粘贴一次** 即可，不必重装系统。

数据库 root 密码和程序用的数据库密码在主控 `/root/301-passwords.txt`。这个文件只留在服务器上，不要发到聊天软件里。
