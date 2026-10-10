# 301 系统安全与运营检查清单

对外运营前请逐项确认（主控 `master/`、节点 `node/redi-multi/`、库 `db/redi_admin.sql`）。

## 必做（上线前）

1. **修改所有默认密码**：导入 `db/redi_admin.sql` 后，在后台重置 `admin_users` 账号密码；不要使用仓库 dump 中的 bcrypt 哈希对应弱口令。
2. **节点数据库连接**：通过环境变量配置，勿使用历史压缩包内硬编码密码（已改为 `REDI_DB_*`）。MySQL 仅允许主控/节点内网 IP 访问 `3306`。
3. **删除/忽略敏感文件**：勿提交 `.env`、`.env.swp`、真实 `config.json` 生产 IP；`master/.env` 设置 `APP_DEBUG=false` 并生成 `APP_KEY`。
4. **HTTPS**：主控后台与对外域名启用 TLS；节点跳转脚本尽量走 HTTPS，降低脚本被篡改风险。
5. **多租户**：已为主控域名/分组模型增加 `admin_id` 全局作用域；批量改跳转已校验分组归属。仍建议为每个客户使用独立后台账号并配置 Dcat 角色权限。

## 架构风险（需运维配合）

| 风险 | 说明 | 建议 |
|------|------|------|
| 节点直连 MySQL | 边缘节点可读改 `redi_admin_domain` | 防火墙白名单；DB 账号最小权限（仅必要表 UPDATE/SELECT） |
| `/api.js` 无鉴权 | 任何人可查询某 host 的跳转目标 | 内网监听、限速、或改为主控 API + 签名 |
| 跳转 URL 写入 JS | 恶意 `skip_url` 可能影响访客 | 主控校验 URL；节点侧应对输出做 JSON 编码（后续可增强） |
| `skip_num` 刷量 | 对 `/api.js` 重复请求可抬高统计 | 已要求 `status=1` 才计数；可加 WAF/限速 |

## 数据库

- 建议增加唯一索引：`UNIQUE(admin_id, original_url)`，避免重复域名（可执行 `db/patches/001_unique_domain_per_tenant.sql`）。
- 定期备份 `redi_admin`；勿将含真实用户数据的 dump 提交到公开仓库。

## 依赖与版本

- 主控基于 Laravel 8 + Dcat Admin，计划升级 PHP/Laravel 并锁定完整 `composer.lock`。
- 节点发布：以 `node/redi-multi/` 源码为准重新 `go build`，勿混用压缩包内多个不一致二进制。
