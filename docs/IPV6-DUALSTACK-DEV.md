# IPv4 / IPv6 双栈 — 开发设计

**状态：** 规划。写本文时 `main` **只支持 IPv4**。本文不是已上线说明。

**读者：** Composer 2.5。一次会话只做表里的一行，做完就停。

**配套：** [IPV6-DUALSTACK-IMPLEMENTATION.md](IPV6-DUALSTACK-IMPLEMENTATION.md)（提示词、本机验证、不要用 GitHub Actions）。

---

## 1. 现状

| 面 | 行为 |
|----|------|
| 后台 | `public_ip`、`extra_ips` 只收 IPv4。多个 IPv4 可以挂在**同一条**节点上（一个进程、一个 Token） |
| IPv6 | 没有字段，不能和 IPv4 记在一起 |
| DNS | 只有 `upsertARecords`。四家厂商加手动模式都没有 AAAA |
| A 对账 | `reconcileARecordValues` 会先走 `filterValidIpv4List`。期望列表空时 **直接抛「没有可同步的节点 IP」**，不会把 A 删光 |
| 预检 | CNAME，或（开关打开时）A 命中 pool IPv4 |
| 部署 | `NODE_IPS`、MySQL、firewalld 3306 只认 IPv4 |
| 节点 | 默认 `0.0.0.0:80` / `0.0.0.0:443`。Go 1.19 对 `[::]` 是否同时接受 IPv4，取决于内核 `net.ipv6.bindv6only`（CentOS 7 上为 1 时，只听 `[::]` 会把现有 IPv4 访客弄断） |
| lagran | 只有 IPv4 的 iptables NFQUEUE |

`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)` **不能单独当校验**。它接受 `::ffff:192.0.2.1`、`::1`、链路本地地址。

---

## 2. 第一期目标

同一条节点在原有 IPv4 之外，可选主 IPv6 和其他 IPv6。Pool 继续写 A；有 IPv6 能力的厂商再写 AAAA。

控制面不动：Agent、`REDI_MASTER_URL`、`NODE_IPS`、MySQL、firewalld、lagran 仍只走 IPv4。

默认监听地址也不改。改成 `[::]` 是操作员事后的选择，而且排在「把 IPv4 映射地址折回 IPv4」的代码之后。见 V6-21、V6-22。

---

## 3. 必须避开的陷阱

这些是上一版方案里的错误，实现时按这里，不要按「空方法 + 改原接口」做。

1. **不要改 `DnsProviderInterface`。** 加一个新接口 `SupportsPoolAAAA`。厂商做完才 `implements` 它。`syncAll` 只有 `instanceof` 才调用 AAAA；否则保持今天的行为。禁止用空方法假装同步成功。
2. **不要把 AAAA 塞进 `reconcileARecordValues`。** 那个函数会把 IPv6 滤掉，列表空了还会抛错，A 记录的「空列表即失败」不能照搬。AAAA 用新方法：用 IPv6 规范化键比较；期望为空时删掉查到的 AAAA，且不抛「没有可同步的节点 IP」。
3. **删改记录时类型必须是 AAAA。** 复制 A 的代码却留下 `type=A`，会删掉 pool 的 A。每个厂商测试都要证明列表请求的类型是 AAAA。
4. **比较地址用 `inet_pton` 的 16 字节，不要只 `strtolower`。** `2001:db8::1` 和 `2001:0db8:0000::1` 是同一条。只比字符串会每次同步都删了再建。
5. **存库用 `inet_ntop(inet_pton(...))`。** 列长 45 只够这种规范写法，不够带 `%eth0` 或方括号的写法。
6. **`extra_ipv6` 用 `text` + 数组 cast，和 `extra_ips` 一样。** 不要用数据库 JSON 类型。
7. **只加 Laravel 迁移，不要改 `db/redi_admin.sql`。** 绿场是先导入 SQL 再 `migrate`。SQL 里提前加上列，迁移会再加一次然后失败。
8. **后台 IPv6 用文本框，不要用 Dcat 的 `ip()`。** `ip()` 按 IPv4 控件处理。
9. **不要把 IPv6 并进 `publicIps()` / `enabledPublicIps()`。** 那两个函数的返回值会进 A 记录。混进去之后，要么 A 被滤空然后同步抛错，要么写进错误类型。
10. **预检是「或」，不是替换。** 现有 CNAME / A 成功仍然成功。AAAA 只在新开关为 true 时多一条成功路径。默认 false。
11. **不要为「没填 IPv6」加 health 告警。** IPv4 仍是必填，多数节点不会有 IPv6。
12. **不要改 `HostNameValidator`。** 客户 `original_url` 今天已经拒绝任何 IP（含 IPv6）。
13. **`ManualDnsProvider` 不要实现 `SupportsPoolAAAA`。** 手动模式在 `syncAll` 里进 API 之前就会返回。给它加空 AAAA 会和「未实现就跳过」搅在一起。
14. **监听不要在代码里改成 `[::]`。** `bindv6only=1` 时 IPv4 会挂。映射地址 `::ffff:a.b.c.d` 进 `SanitizeClientIP` 后会和原来的 IPv4 字符串不是同一把限流键。先做 V6-21，再在文档里告诉操作员如何自己改监听。

---

## 4. 数据规则

| 字段 | 存储 | 含义 |
|------|------|------|
| `public_ipv6` | 可空 `string(45)` | 主 IPv6，可空 |
| `extra_ipv6` | 可空 `text`，cast 数组 | 同一节点上的其他 IPv6 |

- IPv4 主地址仍然必填。IPv6 整组可选。
- 填了其他 IPv6 却没有主 IPv6：拒绝。
- 其他 IPv6 里与主地址相同的项丢掉。
- 拒绝：IPv4、方括号、`%` zone、未指定 `::`、环回 `::1`、IPv4 映射、IPv4 兼容（前 12 字节为 0）、链路本地 `fe80::/10`、组播 `ff00::/8`、ULA `fc00::/7`。
- `2001:db8::/32` 属于全球单播，校验放行（测试用）。不要写进生产 DNS 的责任在操作员。
- 查重只比 IPv6 列，不和 IPv4 放进同一个集合。
- 模型 `saving` 里对新字段去掉控制字符，做法对齐现有的 `extra_ips`。放在后台那一行做，不要放进迁移那一行。

---

## 5. 同步顺序

`syncAll` 在 A 成功之后、租户 CNAME 之前：

- provider 不是 `SupportsPoolAAAA`：什么都不做（和现在一样）。
- 是：调用 `upsertAAAARecords($fqdn, enabledPublicIpv6s())`，空数组也调用。
- A 抛错：不要调用 AAAA，也不要写 CNAME。
- AAAA 抛错：不要写 CNAME。错误文本含「AAAA」，并写明 pool 的 A 可能已经更新。
- 日志在成功时加上 AAAA 条数。

一家厂商合并后，只有那一家开始删建 AAAA。其他家仍只同步 A。这是故意的，避免一次改四家。

---

## 6. PR 序列

| ID | 只做什么 | 完成标准 |
|----|----------|----------|
| V6-01 | `RediNode::isValidPublicIpv6` | 用 `inet_pton` 的 16 字节判断第 4 节的拒绝项。不用单独的 `FILTER_FLAG_IPV6` 了事。IPv4 函数不动 |
| V6-02 | `canonicalPublicIpv6`：非法返回 `''`，合法返回 `inet_ntop` | `2001:0db8::1` 与 `2001:db8::1` 结果相同 |
| V6-03 | `filterValidIpv6List`：去空、按 16 字节去重、元素为规范字符串 | 不读库 |
| V6-04 | 迁移：两列，`text` 不是 JSON 类型。不动 `redi_admin.sql` | 迁移可 up/down；不改 PHP 行为 |
| V6-05 | `publicIpv6s()`：只读新列，跳过非法值，主地址在前 | 不改 `publicIps()` |
| V6-06 | `parseExtraIpv6` | 非法项的错误里能看到「IPv6」和原文 |
| V6-07 | `assertIpv6Available` | 只和别的节点的 IPv6 冲突 |
| V6-08 | 后台主 IPv6：`text` 不是 `ip()`；空值允许 | 不填时保存结果与现在相同 |
| V6-09 | 后台其他 IPv6；无主地址却有附加地址则拒绝；去掉与主地址重复的项 | 不改 IPv4 文本框的校验 |
| V6-10 | `enabledPublicIpv6s()` | 停用节点不出现；不把结果塞进 `enabledPublicIps()` |
| V6-11 | 新接口 `SupportsPoolAAAA::upsertAAAARecords`，没有任何类实现它 | `DnsProviderInterface` 与 `syncAll` 不变 |
| V6-12 | `reconcileAAAARecordValues` | 空期望会删已有行且不抛「没有可同步的节点 IP」；规范形式不同但地址相同则不删不建；不调用 `filterValidIpv4List`；`reconcileARecordValues` 源码不动 |
| V6-13 | 仅 Cloudflare 实现接口，列表类型 `AAAA` | 测试证明没有对 type `A` 发删除 |
| V6-14 | 仅阿里云，同样约束 | 不改 Cloudflare |
| V6-15 | 仅 DNSPod | 不改前两家 |
| V6-16 | 仅 dns.com | 不改前三家 |
| V6-17 | `syncAll` 按第 5 节调用 | 非 `instanceof` 的假 provider 时 AAAA 调用次数为 0；A 失败时也为 0 |
| V6-18 | 手动参考表：有 IPv6 才多 AAAA 行 | 全是 IPv4 时行集与现在一致 |
| V6-19 | `aaaaRecordPointsToPool`，**不要**接到 `pointsToEdge` | 现有预检测试一个不改 |
| V6-20 | 接到 `pointsToEdge`：CNAME 或 A 或（开关为 true 时）AAAA。配置默认 false | 默认 false 时，只有 AAAA、没有 CNAME/A 仍然失败 |
| V6-21 | `SanitizeClientIP` 把 `::ffff:a.b.c.d` 收成 `a.b.c.d` | 普通 IPv6 保持不变；不改监听默认值 |
| V6-22 | 只改 `DEPLOY-centos7.md`：`bindv6only`、如何自愿把监听改成 `[::]`、如何改回 `0.0.0.0`、lagran 仍不管 IPv6 | 不改 Go、不改默认 `config.json` |
| V6-23 | `vars.example.env` 注释：IPv6 只填后台，禁止写入 `NODE_IPS` | `redi_is_ipv4` 不动 |

依赖向下。V6-13 及以后的厂商可以在 V6-12 合并后一个一个做。V6-17 可以在第一家厂商合并后就做：还没实现接口的厂商保持只同步 A。

---

## 7. 不做

- 用 IPv6 连主控、改 `NODE_IPS`、给 3306 加 IPv6 rich rule
- 主控 Nginx 听 IPv6
- ip6tables / lagran
- 把 IPv4 与 IPv6 收成一个通用 IP 函数
- 代码里把默认监听改成 `[::]`
- health 催促填写 IPv6
