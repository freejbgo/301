# 301 系统安全说明

**新手请先看 [DEPLOY.md](DEPLOY.md)**，按步骤部署即可。

## 已在代码/库里做好的事

- 数据库 `redi_admin.sql`：单管理员 `admin`、默认密码见 DEPLOY.md；域名表 **唯一索引** `(admin_id, original_url)`。
- 主控：多租户隔离、跳转 URL 仅允许 `http/https`、`.env.example` 默认 `APP_DEBUG=false`。
- 节点：`REDI_DB_*` 环境变量；`status=1` 才跳转计数；`api.js` JSON 输出 + 按 IP 限速；**不再清空整表 iptables OUTPUT**。
- `composer.json` 声明 Laravel 8 + Dcat Admin。CentOS 7 用发布包里的 `vendor`，不用在服务器上执行 `composer install`。

## 你必须在服务器上完成的

1. **修改所有默认密码**（后台 admin、MySQL、`.env` 的 `DB_PASSWORD`、节点 `env.sh`）。
2. **MySQL 不对公网开放 3306**（见 `db/mysql_setup.example.sql`；节点出口 IP 用 `scripts/centos7/grant-node-mysql.sh`，防火墙只放行这些 IP）。
3. **关闭 DNS 反查**：主控执行 `scripts/centos7/apply-mysql-skip-name-resolve.sh`。
4. **主控启用 HTTPS**（`ADMIN_HTTPS=true`，并且 Nginx 上的证书和 `APP_URL` 一致）。
5. **防火墙** 只开放 22/80/443。节点不必对访客开放 8080。不要执行 `iptables -F`。

## 边缘池与证书（一期）

| 项 | 说明 |
|----|------|
| 节点 API Token | 格式 `{节点ID}.{随机串}`，仅存 bcrypt 哈希；创建节点时**仅展示一次** |
| `/api/nodes/*` | Bearer 鉴权，与 Dcat 会话分离；可拉取 **全平台** 证书私钥（设计为受信节点，须物理/网络隔离） |
| 节点 `ack` | 仅当存在该节点对应的 `redi_cert_deployments` 行时才接受，避免伪造部署成功 |
| 平台 DNS 表单 | 仅超级管理员可提交（与「DNS 与节点池」页面一致） |
| Session Cookie | HTTPS 后台请设 `SESSION_SECURE_COOKIE=true`（见 `.env.example`） |
| 证书存储 | `redi_domain_certs` 中 PEM 经 Laravel `Crypt` 加密；备份库即备份密钥材料 |
| ACME | 主控 `storage/app/acme` 存 LE 账户；签发前要求节点上报挑战已同步，并探测公网域名 HTTP-01 |
| 节点注册 | Agent 不能通过 API 修改节点公网 IP；IP 只在后台「节点管理」维护 |
| 证书队列 | 仅能为当前管理员名下域名入队；同一时刻只跑一个 ACME 任务 |
| 队列 | 生产请 `QUEUE_CONNECTION=database`，并常驻 `queue:work --sleep=3 --timeout=320 --tries=100` |

## 架构说明（已知限制）

| 项 | 现状 | 建议 |
|----|------|------|
| 节点直连 MySQL | 仍为当前架构，便于部署 | DB 账号最小权限；IP 白名单；长期可改主控 API |
| `/api.js` 无登录 | 跳转业务需要可被浏览器访问 | 防火墙 + 限速（已部分实现） |
| `lagran` | 需 root/CAP_NET_ADMIN | 仅在跳转节点启用；评估是否必须 |

## 依赖

发布包 `master/master.tar.gz` 已含 `composer install --no-dev` 的 `vendor`，不含 `.env`。服务器使用 PHP 7.3 或 8.0。`vendor` 目录本身不进 git。
