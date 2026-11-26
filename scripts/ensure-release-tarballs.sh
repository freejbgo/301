#!/usr/bin/env bash
# 部署脚本共用：若 vars 未指定 tar 且默认路径不存在，则在仓库内尝试构建发布包。
# 一条命令安装用仓库 pkg/。自己跑 deploy 时，先把 pkg/ 或 build-release-tarballs.sh 的产物准备好，再设 REDI_*_TAR（见 docs/RELEASE-PACKAGES.md）。
# 开发机打 tar 前建议先跑 scripts/dev-preflight.sh（见 docs/DEVELOPMENT.md）。
# shellcheck shell=bash

redi_stage_built_tar() {
  local built="$1"
  local dest="$2"

  [[ -f "$built" ]] || return 1
  if [[ "$built" != "$dest" ]]; then
    mkdir -p "$(dirname "$dest")"
    cp -f "$built" "$dest"
  fi
  return 0
}

redi_ensure_master_tar() {
  local repo_root="$1"
  local tar_path="$2"
  local die_fn="${3:-}"

  if [[ -f "$tar_path" ]]; then
    return 0
  fi
  if [[ "${REDI_ALLOW_ON_SERVER_TAR_BUILD:-}" != "1" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "未找到主控发布包: $tar_path。克隆仓库后可留空 REDI_MASTER_TAR 以使用 pkg/master.tar.gz，或在发版机 build-release-tarballs.sh 后 scp 并设置 REDI_MASTER_TAR；仅实验室可在 vars.env 设 REDI_ALLOW_ON_SERVER_TAR_BUILD=1 允许机房内打包"
    return 1
  fi
  echo "未找到主控发布包: $tar_path"
  echo "尝试在仓库内执行 scripts/build-master-tar.sh（需 composer 与网络）…"
  if [[ ! -x "${repo_root}/scripts/build-master-tar.sh" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "请先在开发机执行 scripts/build-release-tarballs.sh（或 build-master-tar.sh），scp master.tar.gz 到本机并设置 REDI_MASTER_TAR"
    return 1
  fi
  if ! command -v composer >/dev/null 2>&1; then
    [[ -n "$die_fn" ]] && "$die_fn" "本机无 composer，无法自动打包。请从开发机上传 master.tar.gz 并设置 REDI_MASTER_TAR（见 docs/RELEASE-PACKAGES.md）"
    return 1
  fi
  if [[ ! -f "${repo_root}/master/master/vendor/autoload.php" ]]; then
    (cd "${repo_root}/master/master" && composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader)
  fi
  (cd "$repo_root" && bash scripts/build-master-tar.sh)
  local built="${repo_root}/master/master.tar.gz"
  if [[ ! -f "$tar_path" ]]; then
    if ! redi_stage_built_tar "$built" "$tar_path"; then
      [[ -n "$die_fn" ]] && "$die_fn" "构建后仍缺少 $tar_path（产物应在 ${built}）"
      return 1
    fi
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
  if [[ "${REDI_ALLOW_ON_SERVER_TAR_BUILD:-}" != "1" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "未找到节点发布包: $tar_path。克隆仓库后可留空 REDI_NODE_TAR 以使用 pkg/redi-multi.tar.gz，或在发版机上传 redi-multi.tar.gz 并设置 REDI_NODE_TAR；实验室可设 REDI_ALLOW_ON_SERVER_TAR_BUILD=1"
    return 1
  fi
  echo "未找到节点发布包: $tar_path"
  echo "尝试在仓库内执行 scripts/build-node-tar.sh（需 Go 工具链）…"
  if [[ ! -x "${repo_root}/scripts/build-node-tar.sh" ]]; then
    [[ -n "$die_fn" ]] && "$die_fn" "请先在开发机执行 scripts/build-release-tarballs.sh（或 build-node-tar.sh），scp redi-multi.tar.gz 并设置 REDI_NODE_TAR"
    return 1
  fi
  if ! command -v go >/dev/null 2>&1; then
    [[ -n "$die_fn" ]] && "$die_fn" "本机无 go，无法自动打包节点。请从开发机上传 redi-multi.tar.gz 并设置 REDI_NODE_TAR（见 docs/RELEASE-PACKAGES.md）"
    return 1
  fi
  (cd "$repo_root" && bash scripts/build-node-tar.sh)
  local built="${repo_root}/node/redi-multi.tar.gz"
  if [[ ! -f "$tar_path" ]]; then
    if ! redi_stage_built_tar "$built" "$tar_path"; then
      [[ -n "$die_fn" ]] && "$die_fn" "构建后仍缺少 $tar_path（产物应在 ${built}）"
      return 1
    fi
  fi
  echo "已生成 $tar_path"
}
