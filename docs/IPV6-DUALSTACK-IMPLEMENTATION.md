# IPv4 / IPv6 双栈 — 实施说明（Composer 2.5）

编号和陷阱以 [IPV6-DUALSTACK-DEV.md](IPV6-DUALSTACK-DEV.md) 为准。两份不一致时改实施说明，不要改设计里的编号含义。

---

## 1. 一次只做一行

```text
按 docs/IPV6-DUALSTACK-DEV.md 实现 V6-0N，只做这一行。
不要改 IPv4 函数、reconcileARecordValues、DnsProviderInterface、默认监听。
不要实现下一行。不要使用 GitHub Actions。
做完在仓库根目录执行 bash scripts/dev-preflight.sh，把退出码和 PHPUnit 计数写进 PR 说明。
```

分支：`cursor/ipv6-v6-0N-<短词>-bb20`。合进 `main` 再开下一个。

出现这些情况就整次作废：用正则拒绝 IPv6、用「前 12 字节为 0」判断映射地址、把 AAAA 并进 A 的对账、改 `DnsProviderInterface`、把发布开关默认写成 true、改 `config.json` 的监听、给 `public_ipv6` 加 `unique()`、在 `upsertAAAARecords` 里调用 `filterValidIpv4List` 或 `filterValidIpv6List`、A 预检未命中时直接 `return`、先删橙色云再创建、只改阿里云或 DNSPod 的子类、把 AAAA 判断放到 CNAME 前面、在 `saving` 里把非法地址改成 null、把 AAAA 放进 A 的 catch、用 `(array) $this->extra_ipv6` 读附加地址。

---

## 2. 可以停的位置

| 已合并到 | 线上效果 |
|----------|----------|
| V6-11 | 后台能存同一节点的 v4 和 v6。DNS 仍只有 A |
| V6-19 | 厂商类能写 AAAA，`syncAll` 仍不调用 |
| V6-21 | 默认仍然不发布。坏的 IPv6 字段在开关关闭时不影响 A。开关打开且启用节点字段坏了，或两列还没齐时，连 A 也不写。停用节点上的坏地址不挡 A |
| V6-27 | 第一期结束。默认监听仍是 `0.0.0.0`，发布开关仍是关 |

---

## 3. 验证

```bash
bash scripts/dev-preflight.sh
```

没有 Docker 就跳过 MariaDB 容器，不要为此去跑 GitHub Actions。Actions 失败不算这一行失败。

开 PR 前跑完整 preflight。定位问题时可以先：

```bash
cd master/master && ./vendor/bin/phpunit --filter 'RediNode|ReconcilesDns|EdgeDns|CnamePrecheck|Cloudflare|Aliyun|Dnspod|DnsCom'
cd node/redi-multi && go test ./util/host/
```

CentOS 真机放在 V6-27 之后：后台同时有 v4/v6；开关仍关时厂商里的 A 不变、也不新出 AAAA；`curl -6` 通 80 之后才打开关，再同步才出现 AAAA；清空地址并保持开关为真再同步，AAAA 消失、A 还在；`NODE_IPS` 仍是 IPv4。

---

## 4. 容易做反的断言

- **V6-01**：`2001:db8::192.0.2.1` 能得到 16 字节。`[2001:db8::1]`、`2001:db8::1%eth0` 不能。`not-an-ip` 得到空字节，不能出现 `strlen():` 这类错误。
- **V6-02**：`fe90::1` 也要失败（`fe80::/10` 不是只有 `fe80` 这个前缀）。
- **V6-03**：不断言某一种手写压缩串。`2001:db8::192.0.2.1` 与 `2001:db8::c000:201` 的规范结果相等。`2001:db8::1.` 与 `2001:db8::1` 相等。
- **V6-05**：不要打开 `db/redi_admin.sql`。
- **V6-09 / V6-10**：表单是 `text`。`extra_ipv6` 存进去之前是数组。两个字段都在 `$fillable` 里。和主地址重复的判断用规范形式：`2001:0db8::1` 不会和主地址 `2001:db8::1` 同时留下。
- **V6-13**：全仓库还没有 `implements SupportsPoolAAAA`。
- **V6-05**：`public_ipv6` 的列定义里没有 `unique()`。
- **V6-14**：`['not-an-ip']` 抛错且删除次数为 0。`[]` 和 `['  ']` 会删。非空合法列表是先调用创建，再调用删除。期望列表是 `['2001:db8::1']`、已有值是 `fe80::1` 时，删除那条旧记录，不抛错。已有值 `2001:0db8::1`、期望 `2001:db8::1` 时，创建和删除次数都是 0。不要只做 `strtolower`。
- **V6-07**：`2001:db8::1` 整段是一个地址，不能被切成多段。`2001:db8::1` 和 `2001:0db8::1` 只存一条。
- **V6-08**：停用节点的附加地址里已有 `2001:db8::1` 时，另一条节点不能再保存这个地址。只和 `public_ipv6` 列相同的检查不算通过。本行还没有 cast：用 `publicIpv6s()` 能读到的形式做测试，不要为了本行去改 `$casts`。
- **V6-09**：字段不是 `required()`。已有节点不填 IPv6 仍能保存。模仿启用开关只提交 `enabled` 的请求时，已保存的主 IPv6 仍在。正常编辑把主 IPv6 清空后，库里是 null。帮助文字里能看到默认不发布。表单提交 `fe80::1` 失败。库里已经是 `fe80::1` 时，只改备注再保存，这个地址还在。`public_ip` 列的展示仍只调用 `publicIps()`。
- **V6-10**：`extra_ipv6` 存成 JSON 数组后，编辑页展示的是一行一个地址，不是整段 JSON。数组里已有 `fe80::1` 时，保存备注不会把它去掉。
- **V6-06 / V6-12**：实现里不要调用 `parseExtraIpv6`，也不要 `(array) $this->extra_ipv6`。`fe80::1` 对 `hasUnreadableIpv6` 为真；`''`、null、`2001:db8::1` 为假。用查询把 `extra_ipv6` 写成不是 JSON 的 `fe80::1` 后，结果仍为真。
- **V6-15～19**：假客户端里先放一条 A。空列表调用后，那条 A 的删除次数是 0。`['not-an-ip']` 的删除次数也是 0。传入 `['2001:db8::1']` 时创建参数里要有这个地址。`upsertAAAARecords` 不调用 `filterValidIpv4List` 或 `filterValidIpv6List`。`upsertARecords` 里的 `filterValidIpv4List` 留着。不要改 `listRecordRows` 现有的类型判断。Cloudflare 列表的 `name` 是完整 FQDN，创建是相对主机名。阿里云、DNSPod 根域创建参数仍是 `@`，不是空字符串。
- **V6-16**：已有灰色云且地址相同则删除次数为 0。`proxiable` 为 true、`proxied` 为 false、地址相同：创建和删除都是 0。只有橙色云且地址相同：请求顺序是创建灰色云，然后删除橙色云；`proxied=false`。灰色云和橙色云都在且地址相同：创建次数为 0，只删橙色云。期望为 `[]`、只有橙色云时，橙色云 id 仍被删除。创建请求失败时，橙色云的删除次数为 0。不要改 `reconcileAAAARecordValues`。
- **V6-17 / V6-18**：`implements SupportsPoolAAAA` 出现在父类，不在 `AliyunCnDnsProvider` 或 `DnspodCnDnsProvider`。AAAA 的创建参数使用 `$this->recordLine`。不要在这次新建调用里写死「默认」或 `Default`。构造函数原来的默认参数不要改。
- **V6-19**：类型字段为空、值是 `1.2.3.4` 的 id，空 AAAA 列表不删。类型为 AAAA 的另一条仍会删。
- **V6-06 / V6-09**：表还没有这两列时，现有节点测试仍能保存。不要去改那些测试的 `Schema::create`。
- **V6-12**：`extra_ipv6` 为字符串 `fe80::1` 时结果为真，不能只看到字母 `f`。
- **V6-20**：不要打开 `scripts/centos7/vars.example.env`。
- **V6-21**：`config(['redi.publish_pool_aaaa' => 'false'])` 时 A 仍会调用，AAAA 为 0，成功日志不出现 `AAAA`。源码里 `config('redi.publish_pool_aaaa'` 的默认参数是 `false`，不能是 `true`。开关为真且启用节点有坏字段，或两列缺一时，A 的次数为 0。只有停用节点是 `fe80::1` 时，A 仍会调用。开关为真、地址列表为空且厂商实现了接口时，成功日志同时含 `pool A` 和 `AAAA x0`。开关为真但假 provider 未实现接口时不抛错，测试能看到 warning，成功日志不出现 `AAAA`。AAAA 失败的错误含「AAAA」，含 A 可能已更新，不含「Pool A 记录同步失败」，且 `dns_synced_at` 仍为空。两台节点各有一个不同 IPv6 时，成功日志的 AAAA 条数是 2，不是 A 记录条数。列不存在时不要出现 SQL 缺列错误。调用 AAAA 之后若走「edge 不在 zone」中止，错误文本同时含「Pool A」和「AAAA」。action 仍是 `sync_pool_and_tenant_cname`。不要把「坏字段检查」拆到另一个 PR。
- **V6-22**：`config` 设成字符串 `"false"` 且地址是 `fe80::1` 时，不增加说明行，也不增加 AAAA 行。开关为真且启用节点是 `fe80::1` 时，有一行无法解析的说明；若同时还有合法地址，合法行也在。只有停用节点是 `fe80::1` 时，不增加说明行。没有 IPv4 时，A 的「请先添加启用节点 IP」仍在。不要改 `PlatformDnsController`。
- **V6-23**：记录数组只有 `ipv6` 键、没有 `ip` 键时仍能命中。`DNS_AAAA` 未定义、或两列不存在时返回 false，测试不抛错。
- **V6-24**：只有 CNAME、没有 A/AAAA 时仍为真。A 记录不是池地址、AAAA 是池地址、两个开关都为真时，`pointsToEdge` 为真。配置是字符串 `"false"` 时，只有 AAAA 则 `pointsToEdge` 为假；这个断言打在 `pointsToEdge` 上，不要只打缓存函数。源码里 `config('redi.dns_precheck_allow_aaaa_to_pool'` 的默认参数是 `false`。缓存键在开关 true 和 false 时不同，并且都能看出客户域名。不要断言键里有子串 `false`。
- **V6-25**：断言结果是 `203.0.113.5`，不是 `::ffff:203.0.113.5`。不要用 `strings.HasPrefix`。`2001:db8::1` 原样返回，不是空字符串，也不是另一种压缩。`203.0.113.1\n` 仍是空。
- **V6-26**：文档必须写 HTTP-01 会跟着 AAAA 走，`curl -6` 在主控上对节点公网 IPv6 做，未听 IPv6 或主控没有 IPv6 出口都不能开发布开关。不要同时绑定 `0.0.0.0` 和 `[::]`，监听示例是 `[::]:80` 而不是 `::80`，并且不要改 `api_listen`。

---

## 5. PR 说明

```text
范围：V6-0N（docs/IPV6-DUALSTACK-DEV.md）
验证：bash scripts/dev-preflight.sh → 退出码 0（PHPUnit N / assertions M）
未做：下一编号；设计文档第 7 节
```
