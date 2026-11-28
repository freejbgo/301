# 节点程序

本仓库的跳转节点面向 **被干扰、被重置、访问不稳的域名**（需要改 TCP 窗口的 **lagran** 配合 80/443 跳转），不是给「完全正常、无干扰」的纯 301 场景设计的。部署时请保持 **lagran 开启**，并给二进制 `CAP_NET_ADMIN`。

**文档：** 安装 [../docs/DEPLOY-BEGINNER-CENTOS7.md](../docs/DEPLOY-BEGINNER-CENTOS7.md) · 脚本 [../DEPLOY-centos7.md](../DEPLOY-centos7.md) · 架构 [../docs/PLATFORM-ARCHITECTURE.md](../docs/PLATFORM-ARCHITECTURE.md) · 平台边界 [../docs/CONSOLE.md](../docs/CONSOLE.md) §8 · 上线清单 [../docs/LAUNCH-CHECKLIST.md](../docs/LAUNCH-CHECKLIST.md)

发布包是 **`redi-multi.tar.gz`**。解压后目录为 `redi-multi/`，里面有源码、`config.json.example`、`env.example` 和编译好的 `bin/redi-multi`。首次部署从 example 复制 `config.json`（`deploy-node.sh` 会自动复制）。**`redirector` 字段不参与运行**，跳转来自 MySQL 与 `default_redirect_url`。线上运行这个二进制，不要 `go run main.go`。

`bin/redi-multi` 是 `CGO_ENABLED=0` 的静态二进制（**linux/amd64**），不依赖 glibc 2.32/2.34。CentOS 7.x 内核小版本不同也不用重编，也不编译内核模块。lagran 使用系统里的 `nfnetlink_queue`。

改完 `node/redi-multi` 源码后，在仓库根目录建议先 `bash scripts/dev-preflight.sh`，再 `./scripts/build-release-tarballs.sh`（或仅节点时 `./scripts/build-node-tar.sh`）。打包结果在 `pkg/redi-multi.tar.gz`，这一份要提交。已经在跑的节点可 `scp` 后设 `REDI_NODE_TAR`（见 [../docs/RELEASE-PACKAGES.md](../docs/RELEASE-PACKAGES.md)）。

## 配置

数据库、主控地址和 Token 放在环境文件里，不要改 `mysqlconnect/mysqlconnect.go`。

| 变量 | 说明 |
|------|------|
| `REDI_DB_HOST` | 主控 MySQL 地址 |
| `REDI_DB_PORT` | 端口，默认 `3306` |
| `REDI_DB_USER` | 用户，生产应为 `redi_node`（仅 EXECUTE `redi_node_bump_skip`） |
| `REDI_DB_PASSWORD` | 密码。只放在服务器上，不要写入仓库。经 `mysql.NewConnector` 建池，可含 `@`、`:`；写入 `env.sh` 时仍须避免 shell 特殊字符 |
| `REDI_DB_NAME` | 库名，默认 `redi_admin` |
| `REDI_DB_TLS` | 可选：`1`/`true` 启用 TLS 并校验证书；`skip-verify` 仅加密不校验（内网自签） |
| `REDI_MASTER_URL` | 主控地址，须 `https://`（lab 可 `REDI_MASTER_INSECURE=1` 允许 `http://`） |
| `REDI_NODE_TOKEN` | 后台「节点管理」这一行「查看 Token」里的 Token |
| `REDI_AGENT_EMPTY_CERT_CLEAR_AFTER` | 可选，默认 `3`；主控连续空证书列表 N 次后才清空本机 TLS 内存（`1` = 立即清空） |
| `REDI_AGENT_EMPTY_CHALLENGE_CLEAR_AFTER` | 可选，默认 `3`；主控连续空 ACME 挑战列表 N 次后才清空本地挑战 |
| `REDI_AGENT_EMPTY_RULES_CLEAR_AFTER` | 可选，默认 `3`；主控连续空 redirect-rules N 次后才清空 force_https 映射 |
| `REDI_CONFIG` | 可选，`config.json` 绝对路径（默认进程工作目录下的 `config.json`；systemd 已设 `WorkingDirectory` 时一般不必设） |
| `REDI_API_LISTEN_INSECURE` | 可选，设为 `1` 才允许 `api_listen` 绑定非 loopback（**生产禁止**；`/api.js` 会触库） |
| `REDI_DB_ALLOW_EMPTY_PASSWORD` | 可选，设为 `1` 才允许空 `REDI_DB_PASSWORD`（**仅 lab**） |

`config.json` 里的 **`server_ip`** 建议与后台「节点管理」主公网 IPv4、`vars.env` 的 `NODE_PUBLIC_IP` 一致，供验收脚本与运维对照；**二进制不读取该字段**，公网 IP 以主控后台为准。`default_redirect_url` 为未命中域名时的默认跳转。示例见 `redi-multi/config.json.example`。

`debug` 须为 JSON **布尔值**（`true` / `false`），不能写 `"0"` / `"1"` 等字符串；类型错误时进程会在启动时 `log.Fatalf`（与 lagran 里部分字段的宽松解析不同）。

给 systemd 用的环境文件写成 `KEY=值`，不要加 `export`。CentOS 7 的 `EnvironmentFile` 不认 `export`。

一台机器只跑一个进程、一个 Token。同一台机器的其他公网 IP 填在后台该节点的「其他公网 IP」，不要再启动一份程序。

## 监听和 lagran

| 配置 | 含义 |
|------|------|
| `addr` | HTTP，默认 `0.0.0.0:80`（Linux 上已双栈）。可用 `REDI_HTTP_LISTEN` 覆盖 |
| `tls_addr` | HTTPS，默认 `0.0.0.0:443`。`""` 或 JSON `null` 关闭 HTTPS |
| `api_listen` | 默认 `127.0.0.1:8080`（仅本机 Echo）；生产跳转走 80/443 同源 `/api.js`。非 loopback 须显式 `REDI_API_LISTEN_INSECURE=1`（生产禁止） |
| `lagran.port` | 出站 TCP 源端口，给 iptables `--sport` 用。字符串 `"80,443"` 或数字 `80` |
| `lagran.window` | 按源端口写窗口，整数 **1–65535**。`"80"` 和 `"443"` 各写一个。没写的端口用 **1**。这是 SYN+ACK、混淆 ACK，以及尚未数满 `split_number` 的纯 ACK 使用的窗口 |
| `lagran.window_scale` | 混淆 ACK 里的 Window Scale，默认 **7**，范围 **0–14** |
| `lagran.confusion_times` | 每个 SYN+ACK 额外发出的 ACK 条数，默认 **7**。`0` 表示不发，最大 **64** |
| `lagran.split_number` | 纯 ACK 的计次阈值，默认 **7**。计次达到这个数之后窗口改为 **28960** |
| `lagran.syn_ack` / `lagran.ack` | 是否处理这两类出站包，默认 **true** |
| `lagran.enabled` | 默认 **true**；仅本地调试可 `REDI_LAGRAN_DISABLED=1` 或设为 false |

SYN+ACK 会去掉 TCP Window Scale。纯 ACK 按上表改窗口。PSH+ACK 和 FIN+ACK 不改，也不再挂 NFQUEUE。旧配置里的 `psh_ack` / `fin_ack` 仍能通过解析，但不起作用。每条流的计次保留 10 秒；新的 SYN+ACK 会把计次重置。

lagran 需要 root，或同时具备 `CAP_NET_ADMIN` 与 `CAP_NET_RAW`（`deploy-node.sh` 会 `setcap`）。`CAP_NET_RAW` 用来发出混淆 ACK。

HTTP/HTTPS 监听器在 `accept` 后对 socket 设置 **`SO_RCVBUF=1`**（见 `http/http.go`）：在 lagran 改写出站 TCP 窗口的场景下，刻意缩小入站接收缓冲，减轻对端灌包占满连接的风险；**不是配置笔误**。

## 跳转与产品行为

- 正常跳转：**HTTP 200 + 页面内 JS**，不是业务层 301；**强制 HTTPS** 在节点 **已安装该域名 TLS 证书** 后才会对 HTTP **301** 到 HTTPS（见 [CONSOLE.md](../docs/CONSOLE.md) §8）。
- 访客在 80/443 请求 `/api.js?d=...`（**须带非空 `d`**）；仅 **启用** 域名会增加 `skip_num`。强制 HTTPS 时 HTTP→HTTPS 本身不计数。
- `/c.js`、`/api.js` 在 80/443 与 8080 均有 IP 限速；`/api.js` 另有按域名限速（见 [SECURITY.md](../SECURITY.md)）。

## 和主控的连接

配置 `REDI_MASTER_URL` + `REDI_NODE_TOKEN` 后，Agent 向主控注册、拉规则与挑战、安装证书；缺一则 Agent 不启动。主控故障时轮询退避至 60s。API 与 revision 行为见 [PLATFORM-ARCHITECTURE.md](../docs/PLATFORM-ARCHITECTURE.md)。

证书 HTTP-01 要求启用节点在线并完成挑战同步；LE 访问客户域时可能落到 pool 任意 A 记录。
