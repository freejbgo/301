# 节点程序

部署整套系统见 [../DEPLOY.md](../DEPLOY.md)。这里只说明节点包本身。

发布包是 **`redi-multi.tar.gz`**。解压后目录为 `redi-multi/`，里面有源码、`config.json`、`env.example` 和编译好的 `bin/redi-multi`。线上运行这个二进制，不要 `go run main.go`。

改完 `node/redi-multi` 源码后执行：

```bash
./scripts/build-node-tar.sh
```

然后提交新的 `node/redi-multi.tar.gz`。部署解压的是这个包。

## 配置

数据库、主控地址和 Token 放在环境文件里，不要改 `mysqlconnect/mysqlconnect.go`。

| 变量 | 说明 |
|------|------|
| `REDI_DB_HOST` | 主控 MySQL 地址 |
| `REDI_DB_PORT` | 端口，默认 `3306` |
| `REDI_DB_USER` | 用户，默认 `redi_admin` |
| `REDI_DB_PASSWORD` | 密码。只放在服务器上，不要写入仓库 |
| `REDI_DB_NAME` | 库名，默认 `redi_admin` |
| `REDI_MASTER_URL` | 主控地址，如 `https://主控域名` |
| `REDI_NODE_TOKEN` | 后台「节点管理」创建节点时只显示一次的 Token |

`config.json` 里把 `server_ip` 改成这台机器的主公网 IPv4，`default_redirect_url` 改成未命中域名时的默认页。示例见 `redi-multi/config.json.example`。

给 systemd 用的环境文件写成 `KEY=值`，不要加 `export`。CentOS 7 的 `EnvironmentFile` 不认 `export`。

一台机器只跑一个进程、一个 Token。同一台机器的其他公网 IP 填在后台该节点的「其他公网 IP」，不要再启动一份程序。

## 监听和 lagran

| 配置 | 含义 |
|------|------|
| `addr` | HTTP，默认 `0.0.0.0:80`。可用 `REDI_HTTP_LISTEN` 覆盖 |
| `tls_addr` | HTTPS，默认 `0.0.0.0:443`。`""` 或 JSON `null` 关闭 HTTPS。可用 `REDI_TLS_LISTEN` 覆盖 |
| `api_listen` | 默认 `0.0.0.0:8080`，明文提供 `/api.js`、`/c.js`。跳转页使用客户域名上的同源路径，不必对访客开放 8080 |
| `lagran.port` | 出站 TCP 源端口，给 iptables `--sport` 用。字符串 `"80,443"` 或数字 `80`。逗号分隔，不要空格 |
| `lagran` | 可整段省略。不要写成空对象后再指望程序猜开关；省略时按示例里的默认开关 |

启动时会把 `addr` 和 `tls_addr` 里的非空端口并进 `lagran.port`。关掉 HTTPS 时不会并入 443。

lagran 需要 root 或 `CAP_NET_ADMIN`：

```bash
sudo setcap cap_net_admin+ep bin/redi-multi
```

## 跳转次数

访客打开跳转页时请求 `/api.js?d=...`。节点直连 MySQL，只有域名状态为启用（`status=1`）时才跳转，并把 `skip_num` 加 1。

## 和主控的连接

配置了 `REDI_MASTER_URL` 和 `REDI_NODE_TOKEN` 之后，进程会向主控注册、拉取跳转规则、同步 HTTP-01 挑战并安装证书。两个变量缺一，Agent 不启动，只剩本机跳转。

证书申请要求每台启用节点都在线并完成挑战同步。Let's Encrypt 访问客户域名时可能落到 pool 里任意一个 A 记录。
