# 已知限制与未来工作

当前 **`main`** 已具备 CentOS 7.9 单路径部署、边缘池 DNS、HTTP-01 证书、节点 Agent 与运营命令。上线阻塞项与验收命令见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)。

## 已具备（摘要）

| 领域 | 说明 |
|------|------|
| 部署 | `deploy-master.sh` / `deploy-node.sh`、`redi_admin.sql` 绿场、节点 `redi_node` + `redi_node_bump_skip` |
| 安全 | 默认密码拦截、dcat-api 须登录、Agent HTTPS、`REDI_MIN_AGENT_VERSION` |
| 证书与节点 | 部署行补齐、revision 分项拉取、续期/prune/health Cron + ops-webhook |
| DNS | 模型 2 pool、六家 API 驱动、CNAME 预检 |
| 运维 | `redi:health` / JSON / Webhook、队列与 validating 阈值、多 worker 文档 |
| IPv6 数据面 | 可选 Pool AAAA、节点双栈监听、lagran ip6tables（见 [NETWORKING-IPv6.md](NETWORKING-IPv6.md)） |

## 排期中的增强（不阻塞首单）

| 项 | 说明 |
|----|------|
| Laravel / PHP 大版本 | Dcat 锁 `2.2.3-beta`；长期升级计划 |
| 更强租户隔离审计 | API/后台全表策略复核 |
| CentOS 7 EOL | 继续使用 vault 源；长期换 OS |

## 产品约定

- 正常跳转：**200 + JS**；仅 **force HTTPS** 为 301。
- 平台内域名占坑：默认停用 + 启用前 DNS。

升级已上线环境：[UPGRADE-IN-PLACE.md](UPGRADE-IN-PLACE.md)。
