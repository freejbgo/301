# 301 系统安全说明

**新手请先看 [DEPLOY.md](DEPLOY.md)**，按步骤部署即可。

## 已在代码/库里做好的事

- 数据库 `redi_admin.sql`：单管理员 `admin`、默认密码见 DEPLOY.md；域名表 **唯一索引** `(admin_id, original_url)`。
- 主控：多租户隔离、跳转 URL 仅允许 `http/https`、`.env.example` 默认 `APP_DEBUG=false`。
- 节点：`REDI_DB_*` 环境变量；`status=1` 才跳转计数；`api.js` JSON 输出 + 按 IP 限速；**不再清空整表 iptables OUTPUT**。
- `composer.json` 已恢复 Laravel 8 + Dcat Admin 依赖声明，部署时执行 `composer install`。

## 你必须在服务器上完成的

1. **修改所有默认密码**（后台 admin、MySQL、`.env` 的 `DB_PASSWORD`）。
2. **MySQL 不对公网开放 3306**（见 `db/mysql_setup.example.sql` 仅内网/本机授权示例）。
3. **主控启用 HTTPS**（`ADMIN_HTTPS=true` 且证书配置正确）。
4. **防火墙** 只开放业务端口。

## 架构说明（已知限制）

| 项 | 现状 | 建议 |
|----|------|------|
| 节点直连 MySQL | 仍为当前架构，便于部署 | DB 账号最小权限；IP 白名单；长期可改主控 API |
| `/api.js` 无登录 | 跳转业务需要可被浏览器访问 | 防火墙 + 限速（已部分实现） |
| `lagran` | 需 root/CAP_NET_ADMIN | 仅在跳转节点启用；评估是否必须 |

## 依赖

主控目录执行：`composer install --no-dev`（需 PHP 7.3+ 与 composer）。不要用仓库里残留的残缺 `vendor` 代替完整安装。
