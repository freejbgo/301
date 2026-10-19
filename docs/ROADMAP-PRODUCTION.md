# 生产能力路线图（P0 / P1 / P2）

**说明：** **P0（阻塞上线）** 与 **运营必备 P1** 已合入 `main`（CentOS 7.9 单路径自 [#75](https://github.com/freejbgo/301/pull/75) 起；完整 PR 列表见 [AUDIT-CENTOS7-PRODUCTION.md](AUDIT-CENTOS7-PRODUCTION.md) §7）。下表 P1/P2 随 `main` 继续演进。

## P0 — 阻塞首次商用上线（已合入 `main`，见 #75 等）

| 项 | 状态 |
|----|------|
| 证书部署行补齐（register / 启用节点） | ✅ |
| Agent 0.1.3 心跳/ack/空证书/限流 | ✅ |
| 后台 dcat-api、默认密码、登录限速、XSS | ✅ |
| 启用前 DNS 指向（CNAME/A 池） | ✅ |
| CentOS 7 部署脚本与 DB 校验路径 | ✅（#72/#73 + #75；仅维护 CentOS） |

## P1 — 上线后日常运营（已合入 `main`，#75–#80 及后续 PR）

| 项 | 状态 |
|----|------|
| `redi:health` / JSON / Webhook；生产下 APP_DEBUG、ACME 邮箱/staging、edge_zone | ✅ |
| 续期 `renew` + dry-run；failed 纳入续期 | ✅ |
| 超管/租户首页看板 | ✅ |
| 节点证书部署列、重试失败（单节点/全局） | ✅ |
| 域名筛选、批量/单行重新下发、CSV 导出（含 CNAME 预检列） | ✅ |
| Cron 失败 `redi:ops-webhook` | ✅ |
| 存量升级文档 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) | ✅ |
| 开发机发版前 `dev-preflight.sh` + AUDIT §9 | ✅ |
| 队列积压 / failed_jobs 健康检查 | ✅ |
| 多机 `queue:work` + `CACHE_DRIVER=redis` 文档与自检 | ✅ `redi:health --json` 的 `hints` + summary 含 queue/cache |
| ACME 续期全状态（validating 等）边界 | ✅ 续期跳过进行中 validating；卡住可重试；health `cert_validating_stuck` |
| CentOS 7.9 真机验收清单自动化 | 📋 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) + CI 容器冒烟 |

## P2 — 架构与合规（未阻塞首单）

| 项 | 状态 |
|----|------|
| Laravel / PHP 大版本升级 | 📋 Dcat 锁 `2.2.3-beta`，`minimum-stability` 暂须 `dev` |
| MySQL 客户端 TLS | ✅ 节点 `REDI_DB_TLS` + DEPLOY-centos7 §9；主控 MariaDB SSL 需自配 |
| lagran NFQUEUE 卸载/升级脚本化 | ✅ `lagran_ports.py` 与进程端口一致 + 诊断/清理 |
| 多机 queue + redis | ✅ [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md) + health hints |
| 更强租户隔离审计（API/后台） | 📋 |
| IPv4/IPv6 双栈（数据面） | 📋 现网只支持 IPv4。V6-01–V6-27，见 [IPV6-DUALSTACK-DEV.md](IPV6-DUALSTACK-DEV.md)。非法地址或未迁移不得被当成空 AAAA 列表。附加 IPv6 读原始列，不要用数组 cast 把坏 JSON 当成空。AAAA 对账用规范地址。查询主机名不要拿去创建 |

## 产品行为（非缺陷）

- 正常跳转：**200 + JS**，不是 HTTP 301；仅 **force HTTPS** 为 301。
- 「域名抢注」指 **平台内** 占 `original_url`，不是注册商；已用「默认停用 + 启用前 DNS」缓解。

## 建议合并与升级顺序

1. 跟踪 **main** → 主控/节点按 [UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md) 升级。  
2. 真机跑 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) 验收。  
3. 配置 Webhook 与监控采集 `redi:health --json`。  
4. 再排 P2 技术债（与业务节奏一起排期）。
