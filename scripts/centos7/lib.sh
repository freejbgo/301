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

redi_is_ipv4() {
  local ip="$1" o
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}

# 按 vars.env 的 NODE_IPS 同步：新增授权/防火墙，移除已删 IP 的账号与规则。
redi_sync_node_ips_access() {
  local script_dir="$1"
  local node_ips="${2:-}"
  local db_pass="$3"
  local mysql_root_pass="$4"
  local state_file="/var/lib/redi/authorized_node_ips"

  mkdir -p /var/lib/redi
  export MYSQL_ROOT_PASSWORD="$mysql_root_pass"

  local -a new_ips=()
  local ip
  for ip in $node_ips; do
    [[ -z "$ip" ]] && continue
    redi_is_ipv4 "$ip" || {
      echo "错误: NODE_IPS 里有非法 IPv4: $ip" >&2
      return 1
    }
    new_ips+=("$ip")
  done

  local -a old_ips=()
  if [[ -f "$state_file" ]]; then
    while IFS= read -r ip || [[ -n "$ip" ]]; do
      [[ -z "$ip" ]] && continue
      old_ips+=("$ip")
    done < "$state_file"
  fi

  local old new found
  for old in "${old_ips[@]}"; do
    found=0
    for new in "${new_ips[@]}"; do
      [[ "$old" == "$new" ]] && found=1 && break
    done
    if [[ "$found" == 0 ]]; then
      echo "移除节点 IP ${old} 的 MySQL 与防火墙"
      if ! bash "${script_dir}/revoke-node-mysql.sh" "$old"; then
        echo "错误: revoke-node-mysql.sh 失败 (${old})" >&2
        return 1
      fi
      if ! bash "${script_dir}/firewalld-revoke-mysql-from-node.sh" "$old"; then
        echo "错误: firewalld-revoke-mysql-from-node.sh 失败 (${old})" >&2
        return 1
      fi
    fi
  done

  for new in "${new_ips[@]}"; do
    bash "${script_dir}/grant-node-mysql.sh" "$new" "$db_pass"
    bash "${script_dir}/firewalld-allow-mysql-from-node.sh" "$new"
  done

  if [[ ${#new_ips[@]} -gt 0 ]]; then
    printf '%s\n' "${new_ips[@]}" > "$state_file"
  else
    : > "$state_file"
  fi
  chmod 600 "$state_file" 2>/dev/null || true
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

# 被干扰域名场景：确保 config.json 中 lagran.enabled 为 true（不依赖 perl）。
redi_ensure_lagran_enabled() {
  local cfg="$1"
  [[ -f "$cfg" ]] || return 0
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$cfg" <<'PY' || return 1
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
lag = data.setdefault("lagran", {})
if lag.get("enabled") is False:
    lag["enabled"] = True
with open(path, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write("\n")
PY
    return 0
  fi
  if grep -qE '"enabled"[[:space:]]*:[[:space:]]*false' "$cfg" 2>/dev/null; then
    sed -i -E 's/("enabled"[[:space:]]*:[[:space:]]*)false/\1true/' "$cfg" || return 1
  fi
}

redi_warn_master_https_mismatch() {
  local admin_https="${1:-false}"
  local master_url="${2:-}"
  [[ "$admin_https" == "true" ]] || return 0
  [[ "$master_url" =~ ^https:// ]] || return 0
  if ! grep -rq 'ssl_certificate' /etc/nginx/conf.d/ 2>/dev/null; then
    echo "警告: ADMIN_HTTPS=true 且 MASTER_URL 为 https，但 /etc/nginx/conf.d/ 中未见 ssl_certificate。" >&2
    echo "       请另建 HTTPS 的 conf.d 片段，或暂时使用 http:// 与 ADMIN_HTTPS=false。" >&2
  fi
}
