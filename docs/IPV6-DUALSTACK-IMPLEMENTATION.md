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

出现这些情况就整次作废：用正则拒绝 IPv6、用「前 12 字节为 0」判断映射地址、把 AAAA 并进 A 的对账、改 `DnsProviderInterface`、把发布开关默认写成 true、改 `config.json` 的监听。

---

## 2. 可以停的位置

| 已合并到 | 线上效果 |
|----------|----------|
| V6-11 | 后台能存同一节点的 v4 和 v6。DNS 仍只有 A |
| V6-19 | 厂商类能写 AAAA，`syncAll` 仍不调用 |
| V6-21 | 默认仍然不发布。坏的 IPv6 字段在开关关闭时不影响 A。开关打开且字段坏了时，连 A 也不写 |
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

- **V6-01**：`2001:db8::192.0.2.1` 能得到 16 字节。`[2001:db8::1]`、`2001:db8::1%eth0` 不能。
- **V6-02**：`fe90::1` 也要失败（`fe80::/10` 不是只有 `fe80` 这个前缀）。
- **V6-03**：不断言某一种手写压缩串。`2001:db8::192.0.2.1` 与 `2001:db8::c000:201` 的规范结果相等。
- **V6-05**：不要打开 `db/redi_admin.sql`。
- **V6-09 / V6-10**：表单是 `text`。`extra_ipv6` 存进去之前是数组。
- **V6-13**：全仓库还没有 `implements SupportsPoolAAAA`。
- **V6-14**：`['not-an-ip']` 抛错且删除次数为 0。`[]` 才会删。非空合法列表是先调用创建，再调用删除。
- **V6-07**：`2001:db8::1` 整段是一个地址，不能被切成多段。
- **V6-12**：`fe80::1` 为真；`''`、null、`2001:db8::1` 为假。
- **V6-15～19**：假客户端里先放一条 A。空列表调用后，那条 A 的删除次数是 0。不要改 `listRecordRows`。
- **V6-16**：已有灰色云且地址相同则删除次数为 0；橙色云则先删再建，`proxied=false`。
- **V6-18**：创建参数里要有 `record_line`。
- **V6-06 / V6-09**：表还没有这两列时，现有节点测试仍能保存。不要去改那些测试的 `Schema::create`。
- **V6-12**：`extra_ipv6` 为字符串 `fe80::1` 时结果为真，不能只看到字母 `f`。
- **V6-21**：`config(['redi.publish_pool_aaaa' => 'false'])` 时 A 仍会调用，AAAA 为 0，成功日志里没有 `AAAA`。开关为真且存在坏字段或列不存在时，A 的次数为 0。不要把「坏字段检查」拆到调用 AAAA 之后的另一个 PR。
- **V6-23**：记录数组只有 `ipv6` 键、没有 `ip` 键时仍能命中。
- **V6-24**：`pointsToEdgeCached` 的键材料里要有开关值。
- **V6-25**：断言结果是 `203.0.113.5`，不是 `::ffff:203.0.113.5`。不要用 `strings.HasPrefix`。
- **V6-26**：文档必须写 HTTP-01 会跟着 AAAA 走，未听 IPv6 不能开发布开关。

---

## 5. PR 说明

```text
范围：V6-0N（docs/IPV6-DUALSTACK-DEV.md）
验证：bash scripts/dev-preflight.sh → 退出码 0（PHPUnit N / assertions M）
未做：下一编号；设计文档第 7 节
```
