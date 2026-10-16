# shellcheck shell=bash
# Ubuntu 22.04 / 24.04 LTS。由 deploy-master.sh、deploy-node.sh source。

redi_is_ipv4() {
  local ip="$1" o
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}

# 返回 apt 包后缀：8.1 或 8.3
redi_ubuntu_php_version() {
  local ver_id="${1:-}"
  case "$ver_id" in
    22.04) echo "8.1" ;;
    24.04) echo "8.3" ;;
    *)
      echo "错误: 仅支持 Ubuntu 22.04 / 24.04 LTS，当前 VERSION_ID=${ver_id:-?}" >&2
      return 1
      ;;
  esac
}

redi_ubuntu_web_user() {
  echo "www-data"
}

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
  for old in ${old_ips[@]+"${old_ips[@]}"}; do
    found=0
    for new in ${new_ips[@]+"${new_ips[@]}"}; do
      [[ "$old" == "$new" ]] && found=1 && break
    done
    if [[ "$found" == 0 ]]; then
      echo "移除节点 IP ${old} 的 MySQL 与防火墙"
      if ! bash "${script_dir}/revoke-node-mysql.sh" "$old"; then
        echo "错误: revoke-node-mysql.sh 失败 (${old})" >&2
        return 1
      fi
      if ! bash "${script_dir}/ufw-revoke-mysql-from-node.sh" "$old"; then
        echo "错误: ufw-revoke-mysql-from-node.sh 失败 (${old})" >&2
        return 1
      fi
    fi
  done

  for new in ${new_ips[@]+"${new_ips[@]}"}; do
    bash "${script_dir}/grant-node-mysql.sh" "$new" "$db_pass"
    bash "${script_dir}/ufw-allow-mysql-from-node.sh" "$new"
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

# 真机 VPS 应有 systemd；无 D-Bus / 容器的实验环境跳过 enable，避免 set -e 中断。
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

redi_ufw_enable_safe() {
  command -v ufw >/dev/null 2>&1 || return 0
  if ufw status 2>/dev/null | grep -qi 'Status: active'; then
    return 0
  fi
  if ! ufw --force enable >/dev/null 2>&1; then
    echo "警告: ufw enable 失败（常见于无 netfilter 权限的容器），已跳过" >&2
  fi
}

redi_warn_master_https_mismatch() {
  local admin_https="${1:-false}"
  local master_url="${2:-}"
  [[ "$admin_https" == "true" ]] || return 0
  [[ "$master_url" =~ ^https:// ]] || return 0
  if ! grep -rq 'ssl_certificate' /etc/nginx/ 2>/dev/null; then
    echo "警告: ADMIN_HTTPS=true 且 MASTER_URL 为 https，但 Nginx 配置中未见 ssl_certificate。" >&2
    echo "       请用 certbot 或另建 sites-enabled 片段，或暂时使用 http:// 与 ADMIN_HTTPS=false。" >&2
  fi
}
