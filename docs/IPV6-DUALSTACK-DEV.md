# IPv4 / IPv6 双栈 — 开发设计

**状态：** 规划。写本文时 `main` **只支持 IPv4**。本文不是已上线说明。

**读者：** Composer 2.5。一次会话只做表里的一行，做完就停。

**配套：** [IPV6-DUALSTACK-IMPLEMENTATION.md](IPV6-DUALSTACK-IMPLEMENTATION.md)。

---

## 1. 现状

| 面 | 行为 |
|----|------|
| 后台 | `public_ip`、`extra_ips` 只收 IPv4。多个 IPv4 可以在同一条节点上（一个进程、一个 Token） |
| IPv6 | 没有字段 |
| DNS | 只有 `upsertARecords`。四家厂商的删除都是按记录 id，不带类型 |
| A 对账 | `reconcileARecordValues` 先 `filterValidIpv4List`。期望为空时抛「没有可同步的节点 IP」，不删光已有 A |
| 预检 | CNAME 到 edge/pool 即通过。另外可用 A 命中 pool IPv4（`dns_precheck_allow_a_to_pool`，默认 true） |
| HTTP-01 | 主控 `Http::get('http://客户域名/...')`。客户若 CNAME 到 pool，解析出 AAAA 时，探测可能先走 IPv6 |
| 节点 | 默认 `0.0.0.0:80` / `0.0.0.0:443`，不接受 IPv6。`net.ipv6.bindv6only` 默认多为 0；**被改成 1** 时，只听 `[::]` 会丢掉 IPv4 |
| 控制面 | `NODE_IPS`、MySQL、firewalld 3306、lagran 只处理 IPv4 |

`filter_var(..., FILTER_FLAG_IPV6)` 会放过 `::ffff:192.0.2.1`、`::1`、`fe80::1`，不能单独当池地址校验。

---

## 2. 第一期目标

同一条节点可以再记可选的主 IPv6 和其他 IPv6。Pool 的 A 记录行为不变。

AAAA **默认不写**。主控 `.env` 里 `REDI_PUBLISH_POOL_AAAA=true` 之后，已经实现 `SupportsPoolAAAA` 的厂商才按地址列表增删 AAAA。节点仍默认听 `0.0.0.0`。先让节点能接受 IPv6 连接，再开这个开关。否则只有 AAAA、80 端口无人接听时，签发和续期的 HTTP-01 会超时。

控制面（Agent、MySQL、`NODE_IPS`、lagran）保持 IPv4。

---

## 3. 陷阱

1. **不要改 `DnsProviderInterface`，不要写空的 AAAA 方法。** 新接口 `SupportsPoolAAAA`。没实现就当没有这次调用。
2. **不要把 AAAA 放进 `reconcileARecordValues`。** 那个函数会丢掉 IPv6，空列表还会抛错。
3. **厂商删除按记录 id，不按类型。** 传进对账的列表里如果混进 A 的 id，空的 IPv6 列表会把 pool 的 A 删掉。查询参数必须是 `AAAA`。测试要看到：库里已有一条 A 时，`upsertAAAARecords($fqdn, [])` 不会删除那条 A 的 id。
4. **地址相同、写法不同必须算同一条。** 用 `inet_pton` 的 16 字节做键。`2001:db8::1`、`2001:0db8::1`、`2001:db8::192.0.2.1` 这种尾部点分 IPv4 都要能收成同一规范形式。不要用 `^[0-9a-f:]+$`，那会拒绝合法的尾部点分写法。
5. **「前 12 字节为 0」拦不住 IPv4 映射地址。** 映射地址只有前 10 字节是 0，第 11、12 字节是 `0xff`。不要用这条规则。全球单播只接受 `2000::/3`：`(ord($bytes[0]) & 0xe0) === 0x20`。这一条同时排除 `::`、`::1`、映射地址、IPv4 兼容地址、`fe80::/10`、`ff00::/8`、`fc00::/7`。
6. **规范字符串不要写死成某一种压缩结果。** 测试比较两次 `canonicalPublicIpv6` 是否相等。再 `strtolower(inet_ntop(...))`。列是 `string(45)`。
7. **`extra_ipv6` 用 `text` + 数组 cast。** 不要用数据库 JSON 类型。空值当 `[]`，不要对 null 做 foreach。后台赋 PHP 数组，不要赋 JSON 字符串，否则 cast 会再编一次。
8. **只加迁移，不改 `db/redi_admin.sql`。** 绿场先导入 SQL 再 `migrate`，SQL 里先加上列会让迁移失败。
9. **后台用 `text`，不用 Dcat `ip()`。**
10. **不要把 IPv6 并进 `publicIps()` / `enabledPublicIps()`。** 这两个的返回值会写成 A。
11. **查重和预检都比 16 字节，不比原始字符串。** `dns_get_record` 的 AAAA 在 `$row['ipv6']`，不在 `$row['ip']`。复制 `resolveA` 会永远匹配不到。
12. **预检是或，不是替换。** CNAME 成功就成功，这是租户的正常路径，**不依赖** AAAA 开关。AAAA 只对应「客户域上直接做了 AAAA、没有 CNAME」这种和现有 A 预检一样的旁路。开关默认 false。缓存键要带上这个开关，否则改配置后 60 秒内仍是旧结果。
13. **发布开关默认 false，而且要用 `filter_var(..., FILTER_VALIDATE_BOOLEAN)` 读。** `.env` 里的 `false` 是字符串，直接当布尔会变成真。开关为 false 时 **零次** AAAA API 调用（也不要借机关掉已有 AAAA）。开关从 true 改回 false **不会**删除已发布的 AAAA；要删除必须保持 true 且地址列表为空。开关为 true 但这家厂商还没实现接口：不要抛错（否则 A 和 CNAME 同步会被掐断），本次跳过 AAAA。
14. **不要为没填 IPv6 加 health 告警。不要改 `HostNameValidator`。`ManualDnsProvider` 不要实现新接口。**
15. **不要改默认监听。** `SanitizeClientIP` 用 `net.ParseIP` 再 `To4()` 把映射地址折回 IPv4。不要只判断字符串前缀 `::ffff:`，`0:0:0:0:0:ffff:203.0.113.5` 是同一地址。普通 IPv6 不要改写成另一种压缩形式。

---

## 4. 数据

| 字段 | 存储 |
|------|------|
| `public_ipv6` | 可空 `string(45)`，主地址，可空 |
| `extra_ipv6` | 可空 `text`，cast 数组 |

IPv4 主地址仍必填。没有主 IPv6 时不能填其他 IPv6。其他项与主地址字节相同则丢掉。模型 `saving` 里只给新字段去控制字符，IPv4 那段不要重写。查重排除自己这条节点。

`ipv6ToBytes`：先 trim；含 `[`、`]`、`%`、`/` 或中间空白则失败；结果必须是 16 字节。`isValidPublicIpv6` 只在此之上要求 `2000::/3`。

---

## 5. 同步

位置：A 成功之后、租户 CNAME 之前。

| 条件 | 动作 |
|------|------|
| 发布开关不是真 | 不调用 AAAA |
| 开关为真但不是 `SupportsPoolAAAA` | 不调用 AAAA，不抛错 |
| 开关为真且是该接口 | 调用 `upsertAAAARecords($fqdn, enabledPublicIpv6s())`，空数组也调用 |
| A 已抛错 | 不调用 AAAA，不写 CNAME |
| AAAA 抛错 | 不写 CNAME。错误含「AAAA」，并说明 A 可能已经写到厂商 |

成功日志加上 AAAA 条数。条数是过滤后的列表长度。

---

## 6. PR 序列

| ID | 只做什么 | 完成标准 |
|----|----------|----------|
| V6-01 | `RediNode::ipv6ToBytes`：trim 后得到 16 字节或空 | 拒绝方括号、`%`、`/`、中间空格、纯 IPv4。接受 `2001:db8::192.0.2.1` 的字节。不做 `2000::/3` 判断 |
| V6-02 | `isValidPublicIpv6` | 只接受 `(ord($b[0]) & 0xe0) === 0x20`。`::ffff:192.0.2.1`、`::1`、`fe80::1`、`fe90::1`、`fc00::1`、`fd00::1`、`ff02::1` 失败。`2001:db8::1` 通过 |
| V6-03 | `canonicalPublicIpv6` | 非法为 `''`。两种写法的返回值相等，且等于 `strtolower(inet_ntop(inet_pton(...)))` |
| V6-04 | `filterValidIpv6List` | 按 16 字节去重，元素是规范字符串 |
| V6-05 | 迁移两列。`extra_ipv6` 为 `text`。不改 `redi_admin.sql` | up/down 可走；不改现有 PHP 行为 |
| V6-06 | `publicIpv6s()` | null 当空数组；跳过非法值；主地址在前；不改 `publicIps()` |
| V6-07 | `parseExtraIpv6` | 错误文本含「IPv6」和原文 |
| V6-08 | `assertIpv6Available` | 用规范形式比；`exceptId` 排除自己；不和 IPv4 比 |
| V6-09 | 后台主 IPv6，`text`，可空 | 不填时与现在相同。`saving` 只追加对新字段的处理 |
| V6-10 | 后台其他 IPv6；无主地址则拒绝；去掉与主地址重复的项 | 赋 PHP 数组。不改 IPv4 校验 |
| V6-11 | `enabledPublicIpv6s()` | 停用节点不出现；不写入 `enabledPublicIps()` |
| V6-12 | 接口 `SupportsPoolAAAA`，零个实现类 | `DnsProviderInterface` 与 `syncAll` 不变 |
| V6-13 | `reconcileAAAARecordValues` | 空期望会删已给的行，且不抛「没有可同步的节点 IP」。同一地址两种写法不删不建。不调用 `filterValidIpv4List`。不改 `reconcileARecordValues` |
| V6-14 | 仅 Cloudflare，`type=AAAA`，新建 `proxied=false` | 已有 A 的 id 在空列表调用时不会被删 |
| V6-15 | 仅阿里云。`listRecordRows(..., 'AAAA')` | 同上。阿里云按 RecordId 删除 |
| V6-16 | 仅 DNSPod，`record_type=AAAA` | 同上。DNSPod 按 record_id 删除 |
| V6-17 | 仅 dns.com，类型 AAAA | 同上 |
| V6-18 | 配置 `redi.publish_pool_aaaa`，环境变量 `REDI_PUBLISH_POOL_AAAA`，默认 false | 不改 `syncAll` |
| V6-19 | `syncAll` 按第 5 节 | 默认 false 时，即使假 provider 实现了接口且列表非空，AAAA 次数也是 0。字符串 `"false"` 也必须是关。A 失败时次数为 0。AAAA 失败时 CNAME 次数为 0 |
| V6-20 | 手动参考表 | 开关为假时，即使库里有 IPv6 也不增加 AAAA 行。开关为真且有地址才增加。全 IPv4 时行集与现在一致 |
| V6-21 | `aaaaRecordPointsToPool`，不接到 `pointsToEdge` | 读 `$row['ipv6']`，用 16 字节和 `enabledPublicIpv6s()` 比。现有预检测试不改 |
| V6-22 | 接到 `pointsToEdge`，并让 `pointsToEdgeCached` 的键包含该开关。配置 `redi.dns_precheck_allow_aaaa_to_pool` 默认 false，读法用 `FILTER_VALIDATE_BOOLEAN` | 默认 false：只有 AAAA、没有 CNAME/A 则失败。true 且字节命中则成功。原有 CNAME 成功用例不变 |
| V6-23 | `SanitizeClientIP` 用 `net.ParseIP` + `To4()` | `::ffff:203.0.113.5` 和 `0:0:0:0:0:ffff:203.0.113.5` 都变成 `203.0.113.5`。`2001:db8::1` 除 trim 外不变。不改监听默认值 |
| V6-24 | 只改 `DEPLOY-centos7.md` | 写明：先 `curl -6` 通 80，再把发布开关设为 true 并 `config:cache`；`bindv6only` 不是 0 就不要改监听；改回 `0.0.0.0:80` / `0.0.0.0:443` 的办法；lagran 不管 IPv6；未听 IPv6 就发布 AAAA 会让 HTTP-01 失败。不改 Go、不改默认 `config.json` |
| V6-25 | `vars.example.env` 注释 | IPv6 只填后台；禁止写入 `NODE_IPS`；发布开关不要在部署脚本里写成 true。不改 `redi_is_ipv4` |

V6-14 起可以在 V6-13 之后逐家做。V6-19 不要求四家都做完。

---

## 7. 不做

- IPv6 连主控、改 `NODE_IPS`、3306 的 IPv6 规则、主控 Nginx 听 IPv6、ip6tables
- 把 IPv4/IPv6 收成一个函数，或改 `HostNameValidator`
- 代码里把默认监听改成 `[::]`
- 没填 IPv6 就 health 告警
- 发布开关默认 true，或在 false 时主动删除已有 AAAA
