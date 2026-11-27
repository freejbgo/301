# 网络：IPv4 / IPv6 双栈

「DNS 与节点池」的参考表只要节点填了公网 IPv6 就列出 AAAA，主控自己不用配置 IPv6。同步到厂商由同一页的 **发布 Pool IPv6（AAAA）** 决定，默认打开，保存在 `redi_platform_dns.publish_pool_aaaa`。数据库还没有这一列时，才回退读取 `REDI_PUBLISH_POOL_AAAA`。

节点监听默认 `0.0.0.0:80` / `0.0.0.0:443`：在 Linux 上 `Listen("tcp")` 已双栈接听，**不要**再绑一条 `[::]`，也不要同时写 `0.0.0.0` 与 `[::]`。只有把 `addr` 改成**具体 IPv4**（非 `0.0.0.0`）时才会只听 IPv4。

控制面（Agent、`NODE_IPS`、MySQL **3306**、主控 Nginx）仍 **仅 IPv4**。节点 **`public_ip`（IPv4）必填**；不能部署「只有 IPv6、没有 IPv4」的节点。

访客数据面：lagran 在 `OUTPUT` 按 **TCP 源端口** 匹配，IPv4 与 IPv6 共用 NFQUEUE 队列号；`iptables` 与 `ip6tables` 规则成对（诊断/清理见 `lagran-diagnose.sh`、`lagran-cleanup-iptables.sh`）。

**部署与逐步验收：** [DEPLOY-centos7.md](../DEPLOY-centos7.md) §12、[ACCEPTANCE.md](ACCEPTANCE.md) §A.7。

---

## 1. 行为摘要

| 面 | 说明 |
|----|------|
| 后台节点 | `public_ip` / `extra_ips`（IPv4）；可选 `public_ipv6` / `extra_ipv6`（同一进程、同一 Token） |
| Pool **A** | 与仅 IPv4 时代相同，由启用节点 IPv4 列表对账 |
| Pool **AAAA** | 后台开关默认打开。打开且 DNS 驱动实现 `SupportsPoolAAAA` 时，对 `enabledPublicIpv6s()` 增删 AAAA；关掉时 **零次** AAAA API 调用 |
| 域名预检 | **CNAME 优先**；可选 A 命中 pool（`dns_precheck_allow_a_to_pool`，默认 true）；可选 AAAA（`dns_precheck_allow_aaaa_to_pool`，默认 false，且依赖发布开关） |
| HTTP-01 | 从**主控**发起；发布 AAAA 后解析可能走 IPv6。打开 AAAA 前须对**每个**将写入 pool 的节点公网 IPv6 在主控执行 `curl -6` 通 80（HTTPS 未关时再测 443） |
| 限流 | 客户端 IP 经 `SanitizeClientIP`；IPv4 映射地址须规范为 IPv4 字面量再作限流键 |

公网 IPv6 校验使用 `inet_pton` + `2000::/3`（全球单播）；`::1`、`fe80::/10`、IPv4 映射地址等 **不能** 写入 Pool AAAA。

---

## 2. 运维：何时打开 `REDI_PUBLISH_POOL_AAAA`

1. 节点已在后台登记 **公网 IPv6**（含「其他 IPv6」），且网卡上地址真实可达。  
2. 确认节点 `config.json` 的 `addr` / `tls_addr` 仍是 **`0.0.0.0`**（或等效双栈通配），未改成单一 IPv4。  
3. 在**主控**上对 `enabledPublicIpv6s()` **每一个**地址 `curl -6` 通 80（示例见验收 §7.2）。主控无 IPv6 出口时不要打开开关。  
4. 开关在「DNS 与节点池」，默认已打开。保存后刷新页面，再执行 DNS 同步。  
5. 分别用 IPv4 / IPv6 访问客户域名，跳转与证书（若已签发）均正常。  

开关从 true 改回 false **不会**自动删除厂商上已有 AAAA；要清空须在开关仍为 true 时把节点 IPv6 清空并同步，或手工在 DNS 厂商删除。

Cloudflare：**橙色云（proxied）** 的 AAAA 不能当已对齐；发布 AAAA 须使用灰色云记录。厂商未实现 `SupportsPoolAAAA` 时同步会 **warning** 并跳过 AAAA，A 与租户 CNAME 仍继续。

---

## 3. DNS 同步顺序（API 模式）

在 `upsertARecords` **之前**：若发布开关为真，但启用节点存在无法解析的 IPv6、或库表尚未迁移出 `public_ipv6`/`extra_ipv6` 列，则 **整次同步失败**（A、AAAA、CNAME 都不写）。

| 条件 | 动作 |
|------|------|
| 发布开关不是真 | 不调用 AAAA；不因 IPv6 字段错误中断 A/CNAME |
| 开关为真，启用节点 IPv6 非法或缺列 | 在写 A 之前抛错 |
| 开关为真，厂商未实现 AAAA | warning，跳过 AAAA；A + CNAME 照常 |
| 开关为真且支持 | A 成功后、`tenant CNAME` 之前调用 `upsertAAAARecords` |
| AAAA 失败 | 不写 CNAME，不更新 `dns_synced_at`；错误文案须区分「A 可能已写入」 |

手动参考表：在现有 Pool A 行后追加 AAAA 行；开关为真但存在非法 IPv6 时须列出合法地址并加说明行，避免运维误删厂商记录。

---

## 4. 明确不支持

- 主控或 MySQL 对节点的 **IPv6 控制面**（`NODE_IPS`、3306 防火墙仍只按 IPv4 授权）  
- 未配置 IPv6 的 health 告警（无 IPv6 不是故障）  
- 把 AAAA 与 A 合并进同一套 `reconcileARecordValues`（AAAA 独立对账）  
- 修改默认监听或 `deploy-node.sh` 仅为「接 IPv6」  

---

## 5. 修改代码时

AAAA / 预检 / Pool 同步逻辑耦合面大，改动后务必：

```bash
bash scripts/dev-preflight.sh
```

并重点跑主控中与 DNS 厂商、节点 IPv6、`RediDnsSync` 相关的 PHPUnit。不要改 `DnsProviderInterface` 签名；新能力用 `SupportsPoolAAAA` 等扩展接口。
