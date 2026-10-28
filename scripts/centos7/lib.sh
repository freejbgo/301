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
# 批量调用 grant/revoke 脚本时设 REDI_GRANT_BATCH=1，避免重复打印单 IP firewalld 提示。
redi_sync_node_ips_access() {
  local script_dir="$1"
  local node_ips="${2:-}"
  local db_pass="$3"
  local mysql_root_pass="$4"
  local state_file="/var/lib/redi/authorized_node_ips"

  mkdir -p /var/lib/redi
  export MYSQL_ROOT_PASSWORD="$mysql_root_pass"

  local -a new_ips=()
  local ip seen
  for ip in $node_ips; do
    [[ -z "$ip" ]] && continue
    redi_is_ipv4 "$ip" || {
      echo "错误: NODE_IPS 里有非法 IPv4: $ip" >&2
      return 1
    }
    for seen in "${new_ips[@]+"${new_ips[@]}"}"; do
      if [[ "$seen" == "$ip" ]]; then
        echo "警告: NODE_IPS 重复 IP ${ip}，已忽略" >&2
        continue 2
      fi
    done
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
  if ! (
    export REDI_GRANT_BATCH=1
    for old in ${old_ips[@]+"${old_ips[@]}"}; do
      found=0
      for new in ${new_ips[@]+"${new_ips[@]}"}; do
        [[ "$old" == "$new" ]] && found=1 && break
      done
      if [[ "$found" == 0 ]]; then
        echo "移除节点 IP ${old} 的 MySQL 与防火墙"
        if ! bash "${script_dir}/revoke-node-mysql.sh" "$old"; then
          echo "错误: revoke-node-mysql.sh 失败 (${old})" >&2
          exit 1
        fi
        if ! bash "${script_dir}/firewalld-revoke-mysql-from-node.sh" "$old"; then
          echo "错误: firewalld-revoke-mysql-from-node.sh 失败 (${old})" >&2
          exit 1
        fi
      fi
    done

    for new in ${new_ips[@]+"${new_ips[@]}"}; do
      if ! bash "${script_dir}/grant-node-mysql.sh" "$new" "$db_pass"; then
        echo "错误: grant-node-mysql.sh 失败 (${new})" >&2
        exit 1
      fi
      if ! bash "${script_dir}/firewalld-allow-mysql-from-node.sh" "$new"; then
        echo "错误: firewalld-allow-mysql-from-node.sh 失败 (${new})" >&2
        exit 1
      fi
    done
  ); then
    return 1
  fi

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
  export REDI_MIN_AGENT_VERSION="${REDI_MIN_AGENT_VERSION:-0.1.3}"
  export REDI_ALLOW_TRUST_ALL_PROXIES="${REDI_ALLOW_TRUST_ALL_PROXIES:-false}"
  php -r '
    $p = $argv[1];
    $env = file_get_contents($p);
    $secure = getenv("ADMIN_HTTPS") === "true" ? "true" : "false";
    $map = [
      "APP_URL" => getenv("MASTER_URL"),
      "DB_PASSWORD" => getenv("DB_PASS"),
      "ADMIN_HTTPS" => getenv("ADMIN_HTTPS"),
      "SESSION_SECURE_COOKIE" => $secure,
      "REDI_MIN_AGENT_VERSION" => getenv("REDI_MIN_AGENT_VERSION"),
      "REDI_ALLOW_TRUST_ALL_PROXIES" => getenv("REDI_ALLOW_TRUST_ALL_PROXIES"),
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
redi_lagran_json_enabled_off() {
  local cfg="$1"
  [[ -f "$cfg" ]] || return 1
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$cfg" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
lag = data.get("lagran") or {}
v = lag.get("enabled", True)
if v is False:
    sys.exit(0)
if isinstance(v, str) and v.strip().lower() in ("0", "false", "no", "off"):
    sys.exit(0)
sys.exit(1)
PY
    return $?
  fi
  grep -qE '"enabled"[[:space:]]*:[[:space:]]*(false|"false"|"off"|"no"|"0")' "$cfg" 2>/dev/null
}

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
v = lag.get("enabled", True)
off = v is False or (
    isinstance(v, str) and v.strip().lower() in ("0", "false", "no", "off")
)
if off:
    lag["enabled"] = True
with open(path, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write("\n")
PY
    return 0
  fi
  if grep -qE '"enabled"[[:space:]]*:[[:space:]]*(false|"false"|"off"|"no"|"0")' "$cfg" 2>/dev/null; then
    sed -i -E 's/("enabled"[[:space:]]*:[[:space:]]*)(false|"false"|"off"|"no"|"0")/\1true/' "$cfg" || return 1
  fi
}

# 节点 deploy 冒烟：与 env.sh 中 REDI_DB_TLS 对齐的 mysql 客户端参数（数组名 redi_mysql_tls_args）。
redi_mysql_tls_args_from_env() {
  redi_mysql_tls_args=()
  local tls="${REDI_DB_TLS:-}"
  [[ -n "$tls" ]] || return 0
  case "$(echo "$tls" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|required|verify)
      redi_mysql_tls_args=(--ssl-mode=REQUIRED)
      ;;
    skip-verify|skip_verify|insecure|skip)
      redi_mysql_tls_args=(--ssl-mode=REQUIRED --ssl-verify-server-cert=OFF)
      ;;
    *)
      echo "警告: 未知 REDI_DB_TLS=$tls，冒烟仍用明文 mysql" >&2
      ;;
  esac
}

redi_systemd_usable() {
  command -v systemctl >/dev/null 2>&1 || return 1
  [[ -d /run/systemd/system ]] || return 1
  systemctl list-units --type=service --no-legend --no-pager >/dev/null 2>&1 || return 1
  return 0
}

redi_svc_enable_now() {
  local unit="$1"
  if redi_systemd_usable; then
    systemctl enable --now "$unit"
  else
    echo "警告: systemd 不可用，跳过 systemctl enable --now ${unit}" >&2
  fi
}

redi_svc_restart() {
  local unit="$1"
  if redi_systemd_usable; then
    systemctl restart "$unit"
  else
    echo "警告: systemd 不可用，跳过 systemctl restart ${unit}" >&2
  fi
}

redi_svc_daemon_reload() {
  if redi_systemd_usable; then
    systemctl daemon-reload
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
