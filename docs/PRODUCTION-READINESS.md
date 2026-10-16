# 生产就绪检查（上线运营 / 全功能）

本文档汇总 **阻塞上线** 与 **验收必做** 项，与 CentOS 7 / Ubuntu 双路径部署文档配合使用。

## 阻塞上线（P0，本仓库已加固）

| 领域 | 要求 |
|------|------|
| 域名占坑 | 默认 **新建为停用**；租户启用前须 CNAME 到 edge 或 **A 到启用节点公网 IP**（`REDI_REQUIRE_DNS_POINTING_TO_ENABLE`，平台管理员可跳过） |
| 后台安全 | 默认密码拦截不再放行 `dcat-api`；`dcat-api` 须已登录后台；登录 10 次/分钟限速；域名列表分组名 HTML 转义 |
| 证书下发 | 节点 register / 重新启用时 `syncDeploymentsForNode` 补齐 `redi_cert_deployments`；主控空证书列表时节点清空内存证书 |
| 节点 Agent | 心跳优先于拉取；拉取失败不阻断后续心跳；ack 403/409 不触发重新 register；Agent ≥ `0.1.3`（`REDI_MIN_AGENT_VERSION`） |
| API 限流 | 节点 API 在认证后按 `node_id` 限流（`throttle:redi-node`） |

## 部署前

1. 修改后台默认密码（种子文档密码在 `config/redi.php` 的 `known_default_admin_passwords` 中会被拦截）。
2. 确认 `APP_KEY` 稳定且已备份（证书密文依赖它）。
3. 主控仅 HTTPS 暴露；节点 `REDI_MASTER_URL` 使用 `https://`（实验环境才用 `REDI_MASTER_INSECURE=1`）。
4. 按 OS 执行：`DEPLOY-centos7.md` 或 `DEPLOY-ubuntu.md`，并准备 `release` 包（见 `scripts/ensure-release-tarballs.sh`）。

## 验收命令（真机）

```bash
# 运营自检（节点心跳、证书到期；可接监控，非 0 表示有待处理项）
cd /opt/301/master/master && php artisan redi:health
php artisan redi:health --json

# 有问题时通知 Webhook（.env 配置 REDI_HEALTH_WEBHOOK_URL，POST JSON，含 text 摘要）
php artisan redi:health --notify

# 续期预演（cron 实际跑的是 redi:renew-certificates，无 --dry-run）
php artisan redi:renew-certificates --dry-run --days=25

# 主控
curl -fsS "https://<master>/api/nodes/revision" -H "Authorization: Bearer <token>"

# 节点健康（部署脚本内已有）
curl -fsS "http://127.0.0.1:<node-port>/revision"

# DB 部署路径（CI 同款）
./scripts/verify-redi-db-deploy-path.sh
```

## 后台操作（运营）

- **节点管理** →「重试失败证书」：仅超级管理员，将该节点 `failed` 部署行改回 `pending`。
- **域名管理** →「重新下发证书」：向全部启用节点重新 `queueDeploy`（租户仅自己的域名）。
- **域名列表筛选「证书」**：待处理 / 已生效 / 14 天内到期 / 未申请。
- **批量操作** →「批量重新下发证书」：对已选中且可解密的证书重新部署。

## 全功能场景

1. **多租户域名**：不同 `admin_id` 域名互不可见；CNAME 预检与跳转规则下发。
2. **多节点**：启用第二节点后，已有 `active` 证书应出现 pending 部署并在节点拉取后 ack。
3. **HTTPS**：签发 → deploying → 各节点 ack → `active`；停用域名后证书不再出现在节点列表。
4. **强制 HTTPS**：仅影响 TLS 层 301；正常跳转仍为 200 + JS（产品行为）。

## 已知非 P0（规划）

- Laravel 8 / PHP 版本升级路线
- MySQL 客户端 TLS
- NFQUEUE 清理与文档中 ack 状态码历史描述对齐

## 回滚

- 主控：恢复上一版 `release` 包与 `php artisan migrate:rollback`（仅在确认无不可逆迁移时使用）。
- 节点：替换 `redi-multi` 二进制并 `systemctl restart redi-node`（或 Ubuntu 等价服务名）。
