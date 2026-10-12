# shellcheck shell=bash
# CentOS 7 已 EOL，yum 源需指向 vault / EPEL 归档。由 deploy-master.sh、deploy-node.sh source。

redi_use_centos7_vault_repos() {
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
    $map = [
      "APP_URL" => getenv("MASTER_URL"),
      "DB_PASSWORD" => getenv("DB_PASS"),
      "ADMIN_HTTPS" => getenv("ADMIN_HTTPS"),
    ];
    foreach ($map as $k => $v) {
      $env = preg_replace("/^".preg_quote($k, "/")."=.*/m", $k."=".$v, $env, 1, $n);
      if ($n !== 1) { fwrite(STDERR, "未写上 {$k}\n"); exit(1); }
    }
    file_put_contents($p, $env);
  ' "$env_file"
}
