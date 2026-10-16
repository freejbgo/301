# 生产能力路线图（P0 / P1 / P2）

**说明：** **P0（阻塞上线）** 与 **运营必备（实为上线路 P1）** 已合并在 PR [#75](https://github.com/freejbgo/301/pull/75)（含 CentOS 7.9 单路径）。「全功能 / 长期运营」还包含下表剩余 P1/P2，会按优先级在 `main` 上继续合入。

## P0 — 阻塞首次商用上线（PR #75 已覆盖）

| 项 | 状态 |
|----|------|
| 证书部署行补齐（register / 启用节点） | ✅ |
| Agent 0.1.3 心跳/ack/空证书/限流 | ✅ |
| 后台 dcat-api、默认密码、登录限速、XSS | ✅ |
| 启用前 DNS 指向（CNAME/A 池） | ✅ |
| CentOS 7 部署脚本与 DB 校验路径 | ✅（#72/#73 + #75；仅维护 CentOS） |

## P1 — 上线后日常运营（PR #75 已大部分覆盖）

| 项 | 状态 |
|----|------|
| `redi:health` / JSON / Webhook | ✅ |
| 续期 `renew` + dry-run；failed 纳入续期 | ✅ |
| 超管/租户首页看板 | ✅ |
| 节点证书部署列、重试失败（单节点/全局） | ✅ |
| 域名筛选、批量/单行重新下发、CSV 导出（含 CNAME 预检列） | ✅ |
| Cron 失败 `redi:ops-webhook` | ✅ |
| 存量升级文档 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) | ✅ |
| 队列积压 / failed_jobs 健康检查 | ✅ |
| 多机 `queue:work` + `CACHE_DRIVER=redis` 文档与自检 | ✅ `redi:health --json` 的 `hints` + summary 含 queue/cache |
| ACME 续期全状态（validating 等）边界 | ✅ 续期跳过进行中 validating；卡住可重试；health `cert_validating_stuck` |
| CentOS 7.9 真机验收清单自动化 | 📋 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) + CI 容器冒烟 |

## P2 — 架构与合规（未阻塞首单）

| 项 | 状态 |
|----|------|
| Laravel / PHP 大版本升级 | 📋 |
| MySQL 客户端 TLS | 📋 |
| lagran NFQUEUE 卸载/升级脚本化 | ✅ 诊断/清理脚本（#77）；升级流程仍随 `deploy-node` / 换包 |
| 更强租户隔离审计（API/后台） | 📋 |

## 产品行为（非缺陷）

- 正常跳转：**200 + JS**，不是 HTTP 301；仅 **force HTTPS** 为 301。
- 「域名抢注」指 **平台内** 占 `original_url`，不是注册商；已用「默认停用 + 启用前 DNS」缓解。

## 建议合并与升级顺序

1. 合并 **#75** → 主控/节点按 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) 升级。  
2. 真机跑 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 验收。  
3. 配置 Webhook 与监控采集 `redi:health --json`。  
4. 再排 P2 技术债（与业务节奏一起排期）。
