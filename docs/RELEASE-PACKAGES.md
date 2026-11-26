# 发布包（主控 + 节点）

生产部署使用 **`master.tar.gz`**（主控）与 **`redi-multi.tar.gz`**（节点）。

一条命令安装直接用仓库里已经打好的包：

| 路径 | 内容 |
|------|------|
| `pkg/master.tar.gz` | 主控，含 `vendor`，不含 `.env` |
| `pkg/redi-multi.tar.gz` | 节点，含静态 `bin/redi-multi` |

`master.sh` 克隆仓库后把这两个文件复制到 `/tmp` 再执行 `deploy-master.sh`。开发机临时产物 `master/master.tar.gz`、`node/redi-multi.tar.gz` 仍 gitignore，不提交。

自己跑 `deploy-*.sh` 时：把 `pkg/` 里的两份拷到 **CentOS 7.9（或其它 7.x）裸机**，或在发版机重新打 tar 后 `scp`，再在 `vars.env` 设置 `REDI_MASTER_TAR` / `REDI_NODE_TAR`。机房**不需要 Docker**，也不从 GitHub Releases 下载。

## 1. 改过程序后重新打包

```bash
git fetch origin main && git checkout main && git pull origin main
bash scripts/dev-preflight.sh          # 与 CI 核心门禁一致，发版前建议必跑
bash scripts/build-release-tarballs.sh # 等价于 build-node-tar + composer --no-dev + build-master-tar
```

要提交并用来安装的是 `pkg/master.tar.gz` 和 `pkg/redi-multi.tar.gz`。`master/master.tar.gz`、`node/redi-multi.tar.gz` 只是打包过程中的中间文件，不提交，部署也不优先用它们。

| 路径 | 部署时解压目标 |
|------|----------------|
| `pkg/master.tar.gz` | `/opt/301/master` |
| `pkg/redi-multi.tar.gz` | `/opt/redi-multi` |

上传到服务器（示例）：

```bash
scp pkg/master.tar.gz pkg/redi-multi.tar.gz root@<主机>:/tmp/
```

在该机 `scripts/centos7/vars.env` 中指定（生产用 [scripts/centos7/vars.production.example.env](../scripts/centos7/vars.production.example.env)；缺 tar 时 **默认失败**，勿在机房自动打包）：

```bash
REDI_MASTER_TAR=/tmp/master.tar.gz
REDI_NODE_TAR=/tmp/redi-multi.tar.gz
```

然后按 [DEPLOY-centos7.md](../DEPLOY-centos7.md) 执行 `deploy-master.sh` / `deploy-node.sh`。

仅更新某一侧时，可单独执行 `scripts/build-master-tar.sh` 或 `scripts/build-node-tar.sh`。这两个脚本也会把结果复制到 `pkg/`。缺 tar 时 `deploy-*.sh` **默认直接失败**；仅在 `vars.env` 设 `REDI_ALLOW_ON_SERVER_TAR_BUILD=1` 时才会在机房尝试 `ensure-release-tarballs.sh`（实验室/容器用）。若 `REDI_*_TAR` 指向其他绝对路径（如 `/tmp/master.tar.gz`），`ensure-release-tarballs.sh` 会在构建后 **复制** 到该路径。

## 2. 包内容

| 文件 | 说明 |
|------|------|
| `master.tar.gz` | 含 `vendor`；**不含** `.env` |
| `redi-multi.tar.gz` | 含静态 `bin/redi-multi`；**不含** 本机 `config.json`（由 example 生成） |

## 3. 提交 `pkg/`

`build-release-tarballs.sh` 会把新包复制到 `pkg/`。提交 `pkg/master.tar.gz` 和 `pkg/redi-multi.tar.gz` 之后，一条命令安装才会用到新包。

安装不从 GitHub Releases 下载。机房没有 composer 或 Go 时，用仓库里的 `pkg/`，不要在服务器上现打包。

| 现象 | 处理 |
|------|------|
| `main` / CI 几秒失败 | 多为 runner/配额；**以本地 `dev-preflight` + 本地 tar 为准** |
| 节点 `ldd` 报 glibc | 必须用本仓库 `build-node-tar.sh` 打出的静态二进制，勿混用其他 Go 构建 |

## 4. 换包与回滚

环境已在跑、需跟上新 tar：[OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md)。保留上一版 tar；回滚见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) §回滚。
