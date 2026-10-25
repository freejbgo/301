# GitHub Releases 发布包

生产部署使用 **`master.tar.gz`**（主控）与 **`redi-multi.tar.gz`**（节点）。仓库 **不提交** tar（已 gitignore）；推荐从 [GitHub Releases](https://github.com/freejbgo/301/releases) 下载，或本地构建后上传机房。

## 何时打 Release

- `main` 上功能与文档已满足上线意图（建议 [LAUNCH-CHECKLIST.md](LAUNCH-CHECKLIST.md) 在预发勾过一遍）。
- 开发机已跑通：`bash scripts/dev-preflight.sh`。

## 发布步骤（维护者）

```bash
git fetch origin main
git checkout main
git pull origin main
bash scripts/dev-preflight.sh

# 语义化版本，须以 v 开头
git tag -a v1.0.0 -m "CentOS 7 主控与节点首次商用包"
git push origin v1.0.0
```

推送 tag 后，Actions 工作流 **「Publish release packages」**（[`.github/workflows/release.yml`](../.github/workflows/release.yml)）会：

1. 跑 Go / PHPUnit（8.4 与 8.0）、`composer audit`、与 CI 对齐的 `verify-*`
2. CentOS 7 容器冒烟（`container-integration-test.sh`）
3. 构建并校验 tar 内容
4. 创建 Release，附件为 **`master.tar.gz`**、**`redi-multi.tar.gz`**

在 GitHub **Actions** 中确认该 workflow **全部绿色** 后，再在 **Releases** 页使用附件部署。

## 包内容

| 文件 | 说明 |
|------|------|
| `master.tar.gz` | 解压到 `/opt/301/master`；含 `vendor`；**不含** `.env` |
| `redi-multi.tar.gz` | 解压到 `/opt/redi-multi`；含静态 `bin/redi-multi`；**不含** 本机 `config.json`（由 example 生成） |

本地等价构建：

```bash
./scripts/build-node-tar.sh
cd master/master && composer install --no-dev -o && cd ../..
./scripts/build-master-tar.sh
```

## 服务器上使用 Release 包

1. 下载对应版本的 `master.tar.gz` / `redi-multi.tar.gz` 到服务器（如 `/tmp`）。
2. 在 `scripts/centos7/vars.env` 中设置（可选，不设则脚本尝试在仓库内构建，生产机通常没有 composer）：

```bash
REDI_MASTER_TAR=/tmp/master.tar.gz
REDI_NODE_TAR=/tmp/redi-multi.tar.gz
```

3. 按 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 执行 `deploy-master.sh` / `deploy-node.sh`。

已上线环境仅换二进制/主控树：见 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)。

## 回滚

保留上一版 Release 的 tar；主控恢复包与 `.env` 备份，节点替换 `redi-multi` 并 `systemctl restart redi-multi`（见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) §回滚）。

## 故障排查

| 现象 | 处理 |
|------|------|
| push tag 后无 Release | 看 Actions 是否失败；修复后删除错误 tag 或打新 patch tag（`v1.0.1`） |
| `main` 上 CI 几秒失败 | 多为 runner/配额；**以 tag 触发的 release workflow 结果为准** |
| 节点 `ldd` 报 glibc | 不要用非本仓库构建的 Go 二进制；必须用 Release 或 `build-node-tar.sh` 产物 |
