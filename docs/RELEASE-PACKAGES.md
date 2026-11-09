# 发布包（主控 + 节点）

生产部署使用 **`master.tar.gz`**（主控）与 **`redi-multi.tar.gz`**（节点）。仓库 **不提交** tar（已 gitignore）。

**推荐路径：** 在发版机本地打 tar → `scp` 到 **CentOS 7.9（或其它 7.x）裸机** → 在 `vars.env` 设置 `REDI_MASTER_TAR` / `REDI_NODE_TAR` → 跑 `deploy-*.sh`。机房**不需要 Docker**；不依赖 GitHub Actions 配额或已有 Release。

## 1. 开发机打包并上传（默认）

```bash
git fetch origin main && git checkout main && git pull origin main
bash scripts/dev-preflight.sh          # 与 CI 核心门禁一致，发版前建议必跑
bash scripts/build-release-tarballs.sh # 等价于 build-node-tar + composer --no-dev + build-master-tar
```

产物（勿提交 git）：

| 路径 | 部署时解压目标 |
|------|----------------|
| `master/master.tar.gz` | `/opt/301/master` |
| `node/redi-multi.tar.gz` | `/opt/redi-multi` |

上传到服务器（示例）：

```bash
scp master/master.tar.gz node/redi-multi.tar.gz root@<主机>:/tmp/
```

在该机 `scripts/centos7/vars.env` 中指定（生产用 [scripts/centos7/vars.production.example.env](../scripts/centos7/vars.production.example.env)；缺 tar 时 **默认失败**，勿在机房自动打包）：

```bash
REDI_MASTER_TAR=/tmp/master.tar.gz
REDI_NODE_TAR=/tmp/redi-multi.tar.gz
```

然后按 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 执行 `deploy-master.sh` / `deploy-node.sh`。

仅更新某一侧时，可单独执行 `scripts/build-master-tar.sh` 或 `scripts/build-node-tar.sh`（见各脚本头注释）。缺 tar 时 `deploy-*.sh` **默认直接失败**；仅在 `vars.env` 设 `REDI_ALLOW_ON_SERVER_TAR_BUILD=1` 时才会在机房尝试 `ensure-release-tarballs.sh`（实验室/容器用）。构建产物默认在仓库内 `master/master.tar.gz`、`node/redi-multi.tar.gz`；若 `REDI_*_TAR` 指向其他绝对路径（如 `/tmp/master.tar.gz`），`ensure-release-tarballs.sh` 会在构建后 **复制** 到该路径。

## 2. 包内容

| 文件 | 说明 |
|------|------|
| `master.tar.gz` | 含 `vendor`；**不含** `.env` |
| `redi-multi.tar.gz` | 含静态 `bin/redi-multi`；**不含** 本机 `config.json`（由 example 生成） |

## 3. 可选：GitHub Releases

维护者可在 `main` 稳定且 `dev-preflight` 通过后打语义化 **`v*`** tag，由 [`.github/workflows/release.yml`](../.github/workflows/release.yml) 在 Actions 可用时构建并挂附件（与本地 `build-release-tarballs.sh` 产物等价）。

```bash
git tag -a v1.0.0 -m "说明"
git push origin v1.0.0
```

Workflow 会跑 Go / PHPUnit（8.4 与 8.0）、`composer audit`、与 CI 对齐的 `verify-*`、CentOS 7 容器冒烟，再上传双 tar。在 **Actions** 全部绿色后，从 [Releases](https://github.com/freejbgo/301/releases) 下载，同样在服务器设置 `REDI_*_TAR` 后部署。

| 现象 | 处理 |
|------|------|
| `main` / CI 几秒失败 | 多为 runner/配额；**以本地 `dev-preflight` + 本地 tar 部署为准** |
| push tag 后无 Release | 看 Actions；修复后删错误 tag 或打新 patch tag |
| 节点 `ldd` 报 glibc | 必须用本仓库 `build-node-tar.sh` 或 Release 附件，勿混用其他 Go 构建 |

## 4. 升级与回滚

已上线环境换包： [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)。保留上一版 tar；回滚见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) §回滚。
