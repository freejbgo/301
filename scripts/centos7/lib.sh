# shellcheck shell=bash
# CentOS 7 已 EOL，yum 源需指向 vault / EPEL 归档。由 deploy-master.sh、deploy-node.sh source。

# 部分云主机/容器只有 IPv4 出口；yum/curl 若先连 IPv6 会长时间失败。
redi_prefer_ipv4_for_yum() {
  if curl -4 --connect-timeout 5 -sSf -o /dev/null https://vault.centos.org/ 2>/dev/null; then
    if ! curl -6 --connect-timeout 3 -sSf -o /dev/null https://vault.centos.org/ 2>/dev/null; then
      if grep -q '^ip_resolve=' /etc/yum.conf 2>/dev/null; then
        sed -i 's/^ip_resolve=.*/ip_resolve=4/' /etc/yum.conf
      else
        echo 'ip_resolve=4' >> /etc/yum.conf
      fi
    fi
  fi
}

redi_use_centos7_vault_repos() {
  redi_prefer_ipv4_for_yum
  local f
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
}

redi_install_remi_release_7() {
  if rpm -q remi-release >/dev/null 2>&1; then
    return 0
  fi
  yum install -y "https://rpms.remirepo.net/enterprise/remi-release-7.rpm"
}

redi_use_epel_archive_repo() {
  if compgen -G "/etc/yum.repos.d/epel*.repo" > /dev/null; then
    sed -i \
      -e 's/^metalink=/#metalink=/' \
      -e 's/^mirrorlist=/#mirrorlist=/' \
      -e 's|^# *baseurl=.*epel/|baseurl=https://archives.fedoraproject.org/pub/archive/epel/|' \
      /etc/yum.repos.d/epel*.repo
  fi
}

redi_sync_master_env() {
  local env_file="$1"
  export MASTER_URL DB_PASS ADMIN_HTTPS
  php -r '
    $p = $argv[1];
    $env = file_get_contents($p);
    $secure = getenv("ADMIN_HTTPS") === "true" ? "true" : "false";
    $map = [
      "APP_URL" => getenv("MASTER_URL"),
      "DB_PASSWORD" => getenv("DB_PASS"),
      "ADMIN_HTTPS" => getenv("ADMIN_HTTPS"),
      "SESSION_SECURE_COOKIE" => $secure,
    ];
    foreach ($map as $k => $v) {
      $env = preg_replace("/^".preg_quote($k, "/")."=.*/m", $k."=".$v, $env, 1, $n);
      if ($n !== 1) {
        $env = rtrim($env, "\n")."\n".$k."=".$v."\n";
      }
    }
    file_put_contents($p, $env);
  ' "$env_file"
}
