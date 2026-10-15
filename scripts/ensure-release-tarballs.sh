#!/usr/bin/env bash
# 部署脚本共用：若 vars 未指定 tar 且默认路径不存在，则在仓库内尝试构建发布包。
# shellcheck shell=bash

redi_ensure_master_tar() {
  local repo_root="$1"
  local tar_path="$2"
  local die_fn="${3:-}"

  if [[ -f "$tar_path" ]]; then
    return 0
  fi
  echo "未找到主控发布包: $tar_path"
  echo "尝试在仓库内执行 scripts/build-master-tar.sh（需 composer 与网络）…"
  if [[ ! -x "${repo_root}/scripts/build-master-tar.sh" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "请先在开发机执行 scripts/build-master-tar.sh，将 master/master.tar.gz 放到服务器并设置 REDI_MASTER_TAR"
    return 1
  fi
  if ! command -v composer >/dev/null 2>&1; then
    [[ -n "$die_fn" ]] && "$die_fn" "本机无 composer，无法自动打包。请上传 master/master.tar.gz 或设置 REDI_MASTER_TAR"
    return 1
  fi
  if [[ ! -f "${repo_root}/master/master/vendor/autoload.php" ]]; then
    (cd "${repo_root}/master/master" && composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader)
  fi
  (cd "$repo_root" && bash scripts/build-master-tar.sh)
  if [[ ! -f "$tar_path" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "构建后仍缺少 $tar_path"
    return 1
  fi
  echo "已生成 $tar_path"
}

redi_ensure_node_tar() {
  local repo_root="$1"
  local tar_path="$2"
  local die_fn="${3:-}"

  if [[ -f "$tar_path" ]]; then
    return 0
  fi
  echo "未找到节点发布包: $tar_path"
  echo "尝试在仓库内执行 scripts/build-node-tar.sh（需 Go 工具链）…"
  if [[ ! -x "${repo_root}/scripts/build-node-tar.sh" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "请先在开发机执行 scripts/build-node-tar.sh，上传 node/redi-multi.tar.gz 并设置 REDI_NODE_TAR"
    return 1
  fi
  if ! command -v go >/dev/null 2>&1; then
    [[ -n "$die_fn" ]] && "$die_fn" "本机无 go，无法自动打包节点。请上传 redi-multi.tar.gz 或设置 REDI_NODE_TAR"
    return 1
  fi
  (cd "$repo_root" && bash scripts/build-node-tar.sh)
  if [[ ! -f "$tar_path" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "构建后仍缺少 $tar_path"
    return 1
  fi
  echo "已生成 $tar_path"
}
