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

1. **不要改 `DnsProviderInterface`，不要写空的 AAAA 方法。** 新接口 `SupportsPoolAAAA`。没实现就当没有这次调用；发布开关为真时要打 warning（见 27）。
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
13. **发布开关默认 false，而且要用 `filter_var(..., FILTER_VALIDATE_BOOLEAN)` 读。** `.env` 里的 `false` 是字符串，直接当布尔会变成真。读的位置在 `syncAll`，不要只在 `config/redi.php` 里转一次：测试会把配置覆盖成字符串 `'false'`。`config('redi.publish_pool_aaaa', false)` 的第二个参数也是 false。旁边 A 预检的默认是 true，不要抄成 true。开关为 false 时 **零次** AAAA API 调用（也不要借机关掉已有 AAAA）。开关从 true 改回 false **不会**删除已发布的 AAAA；要删除必须保持 true 且地址列表为空。开关为 true 但这家厂商还没实现接口：不要抛错（否则 A 和 CNAME 同步会被掐断），本次跳过 AAAA，并打一条 warning。
14. **不要为没填 IPv6 加 health 告警。不要改 `HostNameValidator`。`ManualDnsProvider` 不要实现新接口。** 现有「未配置公网 IP」那句说的是 IPv4，不要改成也要求 IPv6。
15. **不要改默认监听。** `SanitizeClientIP` 用 `net.ParseIP` 再 `To4()`。必须输出 `To4().String()`。`ParseIP().String()` 对映射地址仍是 `::ffff:203.0.113.5`，限流键不会并回去。不要只判断前缀 `::ffff:`。普通 IPv6 不要改写成另一种压缩形式。
16. **`publicIpv6s()` 必须走 `isValidPublicIpv6`，不能只走 `ipv6ToBytes`。** `fe80::1` 也能变成 16 字节，但不是公网地址。只跳过非法字节的话，链路本地地址会被写进 AAAA。
17. **空的主 IPv6 存 `null`，不要存 `''`。** 库里的值用规范字符串。`parseExtraIpv6` 按空白切分，和 `parseExtraIps` 一样，**禁止按 `:` 切分**。
18. **原始字段非空但无法通过 `isValidPublicIpv6` 时，不允许靠「跳过」去同步。** 发布开关为真时，在调用 `upsertARecords` **之前**抛错，A、AAAA、CNAME 都不要写。开关为假时不要为这个原因中断 A 同步。否则一个写坏的地址会被当成空列表，把厂商上的 AAAA 删光。
19. **Cloudflare 上 `proxied=true` 的 AAAA 不算已对齐。** 橙色云会挡住源站 80。不要把这种行放进对账的 existing：地址相同会被当成已经对齐，灰色云补不上。对账只处理灰色云，先补缺的灰色云；**对账正常返回之后**再删橙色云的 id。对账抛错时不要删橙色云。不要改共用的 `reconcileAAAARecordValues`。`proxied` 要从 `listRecords` 的原始行读，对账结构里只有 `id` 和 `value`。
20. **阿里云、DNSPod、dns.com 调用现成的 `listRecordRows(..., 'AAAA')`，不要改这个方法。** dns.com 对「类型字段为空」的行会保留，那是 A 记录路径的现有行为；在 AAAA 这次里改它会连 A 一起变。DNSPod 新建时要带上 `$this->recordLine`，漏掉会建到别的线路或直接失败。不要把构造函数的默认参数抄成写死的「默认」。
21. **节点 firewalld 放行的是 `http`/`https` 服务，这两个服务默认含 IPv6。** 不要再加一条只匹配 IPv4 的 rich rule。这只写进部署说明，不改脚本。
22. **AAAA 对账在期望非空时仍是先增后删。** 和 A 一样，创建失败不能先把旧记录删掉。只有期望本身是空数组（或只有空白）时才只删不建。某个元素 trim 后非空但不是公网 IPv6 时抛错，**不要滤掉后当成空列表**。
23. **没迁移的库不能当成「没有 IPv6」。** `Schema::hasColumn` 为假时，读取函数返回空数组，但不要在这种情况下调用删除。发布开关为真且列不存在：在 `upsertARecords` 之前抛错。现有 sqlite 测试的建表没有这两列，`saving` 和读取都要先看列在不在，不要去改那些测试的建表。
24. **`extra_ipv6` 可能是字符串，不能对字符串 foreach。** 那会按字符遍历。只接受 null 或数组；若是字符串，先 `json_decode` 一次，失败则整段当作一个地址。
25. **没调用 AAAA 时，成功日志保持原来的 `pool A x%d, tenant CNAME x%d`。** 不要写成 `AAAA x0`，否则和「已经按空列表删过」分不清。本次若调用了 AAAA，CNAME 失败和「edge 不在 zone」这两句现成错误要加上「AAAA 可能已更新」。不要改 `RediDnsSyncLog` 的 action 名 `sync_pool_and_tenant_cname`。
26. **空白不是非法地址。** trim 之后为空的元素直接忽略。忽略完什么都不剩，才按空列表删除。trim 之后还有内容、但又不是公网 IPv6，才抛错且不删。`['  ']` 会删；`['not-an-ip']` 不删。
27. **开关为真但这家厂商还没实现接口时，打一条 warning，不要抛错。** 不打日志的话，操作员会以为 AAAA 已经发布了。V6-16 合并之前不要打开发布开关：V6-15 会把地址相同的橙色云 AAAA 当成已经对齐。
28. **手动参考表在开关为真、但启用节点地址非法时，不要显示成「没有 AAAA」。** 合法地址仍逐行列出，再加一行说明有无法解析的 IPv6。开关为假时说明行也不加。否则操作员会按空表把厂商上的 AAAA 手删掉。
29. **A 预检现在是没命中就 return。** `dns_precheck_allow_a_to_pool` 默认 true。若写成 `if (allow_a) return aRecordPointsToPool();` 再写 AAAA，AAAA 分支永远到不了。改成 A 命中才返回 true，没命中再看 AAAA。不要改 A 开关的默认值。`DNS_AAAA` 未定义时，AAAA 预检返回 false，不要 fatal。
30. **预检缓存键必须仍包含客户域名和 edge。** 只把键换成开关值，不同域名会串结果。在原来的 `customer|edge` 后面加上这个开关，用 `(int)` 放进去。布尔 `false` 拼进字符串是空的，不要断言键里有子串 `false`。断言是开关 true 和 false 时两个键不同。
31. **`upsertAAAARecords` 不要先调用 `filterValidIpv6List`，也不要顺手抄上 `filterValidIpv4List`。** `upsertARecords` 开头就是这句 IPv4 过滤。抄进 AAAA 后，合法 IPv6 会被全部丢掉，变成空列表，接着把 AAAA 删掉。`upsertARecords` 里的那句过滤要留着。也不要因为空数组就 return。非法项留给对账抛错。名字拆分、分页、TTL 120 照对应的 `upsertARecords` 抄，只改记录类型和地址。
32. **dns.com 的 `listRecordRows` 会留下类型字段为空的行。** AAAA 在交给对账之前，丢掉值能通过 `isValidPublicIpv4` 的行。不要改这个方法里「类型为空就保留」的判断，否则 A 同步一起变。类型为空且值是 IPv4 的 id，空 AAAA 列表不得删；类型为 AAAA 的行仍参与对账。
33. **坏地址只统计启用节点。** 停用节点上的 `fe80::1` 不能让发布开关中断 A 同步。
34. **节点列表的启用开关只提交 `enabled`。** `form->saving` 的早退不要改条件。请求里没有 IPv6 字段时，不要把库里的值写成 null。`extra_ipv6` 要抄 `extra_ips` 的 `customFormat`，否则编辑页把 JSON 当成一个地址，一保存就失败。
35. **迁移不要加 `unique()`。** `public_ip` 上的 unique 不能照抄。空字符串会撞唯一键。主地址空白存 null，查重只走 `assertIpv6Available`。`public_ipv6` 和 `extra_ipv6` 缺一都算还没迁移。
36. **厂商上已有、但不是公网 IPv6 的值，只要本次期望列表合法或为空，就当对不上并删除。** 不要因为旧记录非法就抛错，否则一条垃圾 AAAA 会让以后每次同步都失败。抛错只针对期望列表里的非法项，而且在任何创建和删除之前。dns.com 在进对账前丢掉的 IPv4 值不在此列（见 32），那些 id 不能删。
37. **`SanitizeClientIP` 在 `To4()` 为 nil 时返回 trim 后的原文，不要返回空字符串。** 控制字符仍返回空。普通 IPv4 仍是原来的点分写法。`To4()` 为 nil 时不要调用 `String()`，也不要改用 `ip.String()`：那会把原生 IPv6 收成另一种压缩。
38. **不要同时监听 `0.0.0.0:80` 和 `[::]:80`。** `bindv6only=0` 时第二个 bind 失败，80 端口整个起不来。只改其中一个地址。443 同样。节点程序只有一个监听地址。
39. **阿里云、DNSPod 的方法写在父类上。** `AliyunCn` / `AliyunIntl` 只是换 endpoint，`DnspodCn` / `DnspodIntl` 也是。只改子类会漏掉另一边。`implements SupportsPoolAAAA` 加在父类。DNSPod 新建 AAAA 用 `$this->recordLine`，不要在这次调用里写死「默认」或 `Default`。国际站构造函数传入的是 `Default`。构造函数原来的默认参数不要改。
40. **新字段要进 `$fillable`。** 模型同时写了 `$fillable` 时，没列进去的字段后台保存会被丢掉。`publicIpv6s` 和 `hasUnreadableIpv6` 不要调用 `parseExtraIpv6`：那个函数是后台校验，非法地址会抛 `ValidationException`，读路径应该跳过或返回真，而不是抛错。
41. **CNAME 判断保持在 A / AAAA 之前。** CNAME 命中就成功。把 AAAA 挪到前面，会让原来只靠 CNAME 的租户被 AAAA 未命中带走。
42. **IPv6 查重看其他节点的 `publicIpv6s()`，含停用节点。** 主地址和附加地址都算。只查 `public_ipv6` 这一列，或只查 `enabled()`，附加地址和停用节点上的地址会重复。不要和 IPv4 互相占用。`inet_pton` 失败时返回 `false`，先判断再读长度；不要把 `false` 交给 `strlen` 或 `ord`。
43. **期望列表为空时，橙色云也要删。** 橙色云不进对账，所以空列表只让对账删到灰色云。对账返回之后仍要删掉全部橙色云 id。不要只在「刚创建了灰色云」时才删。
44. **手动参考表是在现有 A 行后面追加，行还是三列。** 不要重写 A 那一段，也不要因为已经有 IPv6 就拿掉「请先添加启用节点 IP」。不要改 `PlatformDnsController` 的表头。说明行同样是 `[类型, FQDN, 值]`。
45. **`aaaaRecordPointsToPool` 列不存在或 `DNS_AAAA` 未定义时返回 false，不要抛错。** 预检挂在启用域名和签发上，抛错会让这些操作 500。没有可比较的地址时同样返回 false。
46. **`saving` 不要把非法地址改成 null，也不要从数组里删掉。** 表单提交的非法值要报错，不让新写入库。已经在库里的 `fe80::1` 必须原样留着，`hasUnreadableIpv6` 才看得到。清掉之后，发布开关会把它当成空列表，把厂商上的 AAAA 删光。
47. **补 AAAA 说明时保留原来的 Pool A 字样。** CNAME 失败和「edge 不在 zone」现在写的是 Pool A 可能已更新。加上 AAAA 即可，不要整句换成只提 AAAA。成功日志在调用了 AAAA 时仍要有 `pool A` 和 `tenant CNAME`。
48. **节点改听 IPv6 时地址要带方括号。** 写成 `[::]:80` 和 `[::]:443`。`::80` 解析不出端口，lagran 会拿到 `:80`，对不上 80。这只写进部署说明。
49. **AAAA 用单独的 try，不要放进 A 的那个 catch。** A 的文案是「Pool A 记录同步失败」，表示 A 没写上。AAAA 失败时 A 可能已经写上，CNAME 还没写，`dns_synced_at` 也不前进。厂商没实现接口时，成功日志里仍然不要出现 `AAAA`；warning 单独记。

---

## 4. 数据

| 字段 | 存储 |
|------|------|
| `public_ipv6` | 可空 `string(45)`，主地址，可空，不要 `unique()` |
| `extra_ipv6` | 可空 `text`，cast 数组 |

IPv4 主地址仍必填。没有主 IPv6 时不能填其他 IPv6。其他项与主地址字节相同则丢掉。模型 `saving` 里只给新字段去控制字符，IPv4 那段不要重写。查重排除自己这条节点。

`ipv6ToBytes`：先 trim；含 `[`、`]`、`%`、`/` 或中间空白则失败；结果必须是 16 字节。`isValidPublicIpv6` 只在此之上要求 `2000::/3`。

---

## 5. 同步

非法字段或列还没迁移：在 `upsertARecords` **之前**结束。合法发布：A 成功之后、租户 CNAME 之前调用 AAAA。

| 条件 | 动作 |
|------|------|
| 发布开关不是真 | 不调用 AAAA，也不因为 IPv6 字段坏了或缺列而中断 |
| 开关为真，且任一启用节点的原始 IPv6 非空但非法 | 在 `upsertARecords` 之前抛错。A、AAAA、CNAME 都不写。停用节点不算 |
| 开关为真，且两列缺一 | 在 `upsertARecords` 之前抛错。读取函数仍可返回 `[]`，这里不能把这个 `[]` 交给删除 |
| 开关为真但不是 `SupportsPoolAAAA` | 不调用 AAAA，不抛错，打 warning。A 和 CNAME 照常 |
| 开关为真且是该接口 | 调用 `upsertAAAARecords($fqdn, enabledPublicIpv6s())`，空数组也调用 |
| A 已抛错 | 不调用 AAAA，不写 CNAME |
| AAAA 抛错 | 不写 CNAME，不更新 `dns_synced_at`。错误含「AAAA」，并说明 A 可能已经写到厂商。不要用 A 的那句「Pool A 记录同步失败」 |

只有这次真的调用了 AAAA，成功日志才加上 AAAA 条数，并且仍保留 `pool A` 和 `tenant CNAME`。没调用就保持原句。调用了但地址为空时，条数写 0，用来和「没调用」区分。CNAME 失败，以及「有租户 edge 不在平台域名下」那句中止，只要已经调用过 AAAA，都要在原来的 Pool A 说明后面写上 AAAA 可能已更新。

---

## 6. PR 序列

| ID | 只做什么 | 完成标准 |
|----|----------|----------|
| V6-01 | `RediNode::ipv6ToBytes`：trim 后得到 16 字节或空 | 拒绝方括号、`%`、`/`、中间空格、纯 IPv4。接受 `2001:db8::192.0.2.1` 的字节。不做 `2000::/3` 判断。`inet_pton` 为 `false` 时返回空，不要把 `false` 交给 `strlen` |
| V6-02 | `isValidPublicIpv6` | 只接受 `(ord($b[0]) & 0xe0) === 0x20`。`::ffff:192.0.2.1`、`::1`、`fe80::1`、`fe90::1`、`fc00::1`、`fd00::1`、`ff02::1` 失败。`2001:db8::1` 通过 |
| V6-03 | `canonicalPublicIpv6` | 非法为 `''`。函数自己先去掉末尾的点再 `inet_pton`。`2001:db8::1.` 与 `2001:db8::1` 相等；`2001:db8::1` 与 `2001:0db8::1` 相等；`2001:db8::192.0.2.1` 与 `2001:db8::c000:201` 相等。等于 `strtolower(inet_ntop(inet_pton(...)))` |
| V6-04 | `filterValidIpv6List` | 按 16 字节去重，元素是规范字符串 |
| V6-05 | 迁移两列。`extra_ipv6` 为 `text`。不改 `redi_admin.sql` | up/down 只加、只删这两列，不动 `public_ip` 和 `extra_ips`。不改现有 PHP 行为。`public_ipv6` 可空且不要 `unique()` |
| V6-06 | `publicIpv6s()` | 用 `isValidPublicIpv6` 过滤，不能只用 `ipv6ToBytes`。`fe80::1` 不出现。null 和 `''` 当没有。主地址在前。列不存在则返回 `[]` 且不查询该列。不调用 `parseExtraIpv6`。不改 `publicIps()`，也不改现有测试的建表 |
| V6-07 | `parseExtraIpv6` | 用 `preg_split('/\s+/')`，禁止按 `:` 切开。存规范字符串。同一地址两种写法只留一条。错误文本含「IPv6」和原文 |
| V6-08 | `assertIpv6Available` | 用规范形式比其他节点的 `publicIpv6s()`，主地址和附加都算，含停用节点。`exceptId` 排除自己。不和 IPv4 比，也不要只查 `public_ipv6` 列 |
| V6-09 | 后台主 IPv6，`text`，可空 | 不要 `required()`。已有节点没有 IPv6 也要能保存。空白存 `null`，不存 `''`。合法值存规范字符串。表单上的 `fe80::1` 要报错，不要存。`saving` 只去控制字符，不要把已有非法值改成 null。`public_ipv6` 写进 `$fillable`。帮助文字写明默认不发布 AAAA。列不存在时不要把这个属性留在待保存列表里。启用开关那次请求没有该字段时直接 return，不要写成 null |
| V6-10 | 后台其他 IPv6；无主地址则拒绝；去掉与主地址重复的项 | 赋 PHP 数组。`extra_ipv6` 写进 `$fillable`，cast 为数组。和主地址比的是规范形式。`saving` 不要滤掉非法附加地址。`customFormat` 与 `extra_ips` 相同。不改 IPv4 校验，不改启用开关的早退条件。`extra_ips` 帮助文字里「Pool 只写 A 记录」不要改成默认会写 AAAA |
| V6-11 | `enabledPublicIpv6s()` | 停用节点不出现；按 16 字节去重；不写入 `enabledPublicIps()` |
| V6-12 | `hasUnreadableIpv6` | 这一行是单个节点：原始主地址或任一附加项 trim 后非空且 `isValidPublicIpv6` 为假则为真。空白和 null 为假。字符串不要按字符遍历。不调用 `parseExtraIpv6`。列不存在则为假（列的问题交给 V6-21）。不删 DNS，不改 `syncAll` |
| V6-13 | 接口 `SupportsPoolAAAA`，零个实现类 | `DnsProviderInterface` 与 `syncAll` 不变 |
| V6-14 | `reconcileAAAARecordValues` | `[]` 和 `['  ']` 会删已给的行，且不抛「没有可同步的节点 IP」。`['not-an-ip']` 在任何创建和删除之前抛错，删除次数为 0。期望列表合法时，已有的 `fe80::1` 这类非公网值要删，不要为此抛错。非空合法列表先增后删。同一地址两种写法不删不建，重复行只留一条。不调用 `filterValidIpv4List`。不改 `reconcileARecordValues` |
| V6-15 | 仅 Cloudflare 的灰色云增删，`type=AAAA`，新建 `proxied=false` | 已有 A 的 id 在空列表调用时不会被删。`['not-an-ip']` 不删任何 id。`upsertAAAARecords` 里不要调用 `filterValidIpv4List` 或 `filterValidIpv6List`，也不要因空数组 return。`upsertARecords` 里的 IPv4 过滤留着。名字拆分照 `upsertARecords`。本行不处理已经是橙色云的记录 |
| V6-16 | 仅 Cloudflare：橙色云不交给对账；对账成功后再删这些 id | 不改 V6-14 的对账函数。地址相同且已是灰色云时不删不建。已有橙色云、地址相同：先出现创建灰色云，再删橙色云那条 id。期望为空时，对账返回后橙色云 id 的删除次数仍大于 0。对账抛错时橙色云的删除次数为 0 |
| V6-17 | 仅 `AliyunDnsProvider`。调用现成 `listRecordRows(..., 'AAAA')`，不改该方法 | 父类实现接口，Cn / Intl 子类不要各写一份。不删 A 的 id。`['not-an-ip']` 不删任何 id。`upsertAAAARecords` 里不要调用 `filterValidIpv4List` 或 `filterValidIpv6List`。删除参数是 `RecordId` |
| V6-18 | 仅 `DnspodDnsProvider`，`record_type=AAAA`，并传入 `$this->recordLine` | 父类实现接口，Cn / Intl 子类不要各写一份。新建参数不要写死「默认」或 `Default`。构造函数原来的默认参数不要改。不删 A 的 id。`['not-an-ip']` 不删任何 id。`upsertAAAARecords` 里不要调用 `filterValidIpv4List` 或 `filterValidIpv6List`。删除参数是 `record_id` |
| V6-19 | 仅 dns.com，`type=AAAA`。调用现成 `listRecordRows`，不改该方法里的空类型判断 | 不删 A 的 id。类型为空且值为 IPv4 的 id，空列表也不删。类型为 AAAA 的行仍参与对账。`upsertAAAARecords` 里不要调用 `filterValidIpv4List` 或 `filterValidIpv6List`。删除参数是 `record_id` |
| V6-20 | 配置 `redi.publish_pool_aaaa`，环境变量 `REDI_PUBLISH_POOL_AAAA`，默认 false | 不改 `syncAll`。可在 `master/master/.env.example` 加注释。不要改 `scripts/centos7/vars.example.env`，也不要写成 true |
| V6-21 | `syncAll` 按第 5 节 | 开关为假时不因坏字段或缺列中断，AAAA 次数为 0，成功日志不出现 `AAAA`。`config(..., false)` 的默认参数是 false，不要写成 true。开关为真且启用节点 `hasUnreadableIpv6` 或两列缺一时，A 的调用次数为 0；停用节点上的坏地址不算。开关为真但厂商未实现接口时不抛错，并有 warning，成功日志仍不出现 `AAAA`。字符串 `"false"` 仍是关。AAAA 用单独的 try。AAAA 失败时 CNAME 次数为 0，`dns_synced_at` 不前进，错误含「AAAA」和 A 可能已更新，且不是「Pool A 记录同步失败」。调用了 AAAA 之后，CNAME 失败和「edge 不在 zone」两句都同时含「Pool A」和「AAAA」。已调用且地址为空时，成功日志含 `pool A` 和 `AAAA x0`。action 名仍是 `sync_pool_and_tenant_cname` |
| V6-22 | 手动参考表 | 在现有 A 行后面追加，行仍是三列。没有 IPv4 时「请先添加启用节点 IP」那行还在。开关为假时，即使库里有 IPv6 或非法地址，也不增加 AAAA 行或说明行。开关为真且地址合法才增加。开关为真且启用节点非法时，合法地址仍列出，并加一行说明。不改 `PlatformDnsController` 的表头 |
| V6-23 | `aaaaRecordPointsToPool`，不接到 `pointsToEdge` | 读 `$row['ipv6']`，用 16 字节和 `enabledPublicIpv6s()` 比。列不存在、`DNS_AAAA` 未定义、或池地址为空时返回 false，不抛错。现有预检测试不改 |
| V6-24 | 接到 `pointsToEdge`，并让 `pointsToEdgeCached` 的键包含该开关。配置 `redi.dns_precheck_allow_aaaa_to_pool` 默认 false，读法用 `FILTER_VALIDATE_BOOLEAN` | CNAME 命中仍先返回。A 未命中时仍会评估 AAAA，不能沿用 `return aRecordPointsToPool()`。默认 false：只有 AAAA、没有 CNAME/A 则失败。true 且字节命中则成功。缓存键同时有客户域名、edge 和 `(int)` 开关；true 和 false 两个键不同。A 开关默认仍是 true。原有 CNAME 成功用例不变 |
| V6-25 | `SanitizeClientIP` | `To4().String()` 得到 `203.0.113.5`。两种映射写法都覆盖。`ParseIP().String()` 那种 `::ffff:` 结果算失败。`To4()` 为 nil 时返回 trim 原文，不返回空，也不要 `ip.String()`。`2001:db8::1` 除 trim 外不变。带控制字符的输入仍返回空。不改监听默认值 |
| V6-26 | 只改 `DEPLOY-centos7.md` | 写明：先 `curl -6` 通 80，再把发布开关设为 true 并 `config:cache`；`bindv6only` 不是 0 就不要改监听；要改监听就写成 `[::]:80` 和 `[::]:443`，不要写成 `::80`；不要同时绑 `0.0.0.0` 和 `[::]`；改回 `0.0.0.0:80` / `0.0.0.0:443`；lagran 不管 IPv6；未听 IPv6 就发布 AAAA 会让 HTTP-01 失败；不要加只匹配 IPv4 的 firewalld rich rule。不改 Go、脚本和默认 `config.json` |
| V6-27 | `vars.example.env` 注释 | IPv6 只填后台；禁止写入 `NODE_IPS`；发布开关不要在部署脚本里写成 true。不改 `redi_is_ipv4` |

V6-15 起可以在 V6-14 之后逐家做。V6-16 只跟在 V6-15 后面，而且在它合并前不要打开发布开关。V6-21 不要求四家都做完，也不要再拆：坏字段检查必须和第一次可能删除 AAAA 的调用放在同一次提交里。

---

## 7. 不做

- IPv6 连主控、改 `NODE_IPS`、3306 的 IPv6 规则、主控 Nginx 听 IPv6、ip6tables
- 把 IPv4/IPv6 收成一个函数，或改 `HostNameValidator`
- 代码里把默认监听改成 `[::]`
- 没填 IPv6 就 health 告警
- 发布开关默认 true，或在 false 时主动删除已有 AAAA
