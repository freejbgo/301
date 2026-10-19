# IPv4 / IPv6 双栈 — 实施说明（Composer 2.5）

设计、陷阱和 PR 表以 [IPV6-DUALSTACK-DEV.md](IPV6-DUALSTACK-DEV.md) 为准。编号不一致时以那份为准。

---

## 1. 一次只做一行

会话开头只贴下面这一段，把编号换成表里的当前行：

```text
按 docs/IPV6-DUALSTACK-DEV.md 实现 V6-0N，只做这一行。
不要改 IPv4 函数和 reconcileARecordValues。
不要实现下一行。不要使用 GitHub Actions。
做完在仓库根目录执行 bash scripts/dev-preflight.sh，把退出码和 PHPUnit 计数写进 PR 说明。
```

分支：`cursor/ipv6-v6-0N-<短词>-bb20`。合进 `main` 再开下一个。

Composer 2.5 如果开始合并 IPv4/IPv6 函数、修改 `DnsProviderInterface`、或把 AAAA 写进 `reconcileARecordValues`，丢掉这次改动，按设计文档第 3 节重做。

---

## 2. 可以停的位置

| 已合并到 | 线上效果 |
|----------|----------|
| V6-10 | 后台能保存同一节点的 v4 和 v6。DNS 仍只有 A |
| V6-16 | 做完的那几家厂商类已经会写 AAAA，但 `syncAll` 还没调用 |
| V6-17 | 已实现接口的厂商开始同步 AAAA；没实现的仍只同步 A |
| V6-23 | 第一期结束。默认监听仍然是 `0.0.0.0` |

---

## 3. 验证

每个 PR 在仓库根目录：

```bash
bash scripts/dev-preflight.sh
```

本机没有 Docker 时，脚本会跳过 MariaDB 容器。不要为此去跑 GitHub Actions 的 `centos7-integration`。Actions 失败不算这一行失败。

缩小范围只用于定位，开 PR 前仍要完整 preflight：

```bash
cd master/master && ./vendor/bin/phpunit --filter 'RediNode|ReconcilesDns|EdgeDns|CnamePrecheck|Cloudflare|Aliyun|Dnspod|DnsCom'
cd node/redi-multi && go test ./util/host/
```

CentOS 7.9 真机放到 V6-23 合并之后，不要每个小 PR 都做。真机看四件事：后台同时有 v4 和 v6；同步后 A 还在且多了 AAAA；清空 IPv6 再同步则 AAAA 没了、A 还在；`NODE_IPS` 仍是 IPv4，节点连库和 Agent 仍通。把监听改成 `[::]` 是另一项自愿操作，按 V6-22 的回滚步骤做，不要和功能验收绑在一起。

---

## 4. 各行验收时多看一眼

- **V6-01**：`::ffff:192.0.2.1`、`::1`、`fe80::1`、`fc00::1`、`ff02::1`、`[2001:db8::1]`、`2001:db8::1%eth0`、`1.2.3.4` 都拒绝。`2001:db8::1` 通过。
- **V6-02 / V6-03**：两种写法收成同一个字符串，列表里只留一个。
- **V6-04**：文件名用 `2026_10_19_100010_add_node_ipv6.php` 一类、排在现有 `100009` 之后。`extra_ipv6` 是 `text`。不要打开 `db/redi_admin.sql`。
- **V6-05**：sqlite 测试自己建列。不要改已有 `publicIps()` 断言。
- **V6-08 / V6-09**：表单方法是 `text`。帮助文字写明不要把 IPv6 填进「其他公网 IP」。
- **V6-11**：全仓库还没有任何 `implements SupportsPoolAAAA`。
- **V6-12**：用一个只为测试而 `use` 该 trait 的类。断言空列表会调用删除、不会抛「没有可同步的节点 IP」。再断言已有 `2001:0DB8::1`、期望 `2001:db8::1` 时创建次数和删除次数都是 0。
- **V6-13～16**：假 HTTP 记录 `type`。出现 `A` 的删除即失败。不要请求真实厂商。
- **V6-17**：调用顺序是 A，然后 AAAA，然后 CNAME。A 抛错时后两者次数为 0。AAAA 抛错时 CNAME 为 0。
- **V6-19**：`pointsToEdge` 的 diff 应为空。
- **V6-20**：默认配置 false。只有 AAAA 时预检失败；改为 true 且地址等于某启用节点的规范 IPv6 时通过。已有的 CNAME 成功用例不要改期望。
- **V6-21**：`SanitizeClientIP("::ffff:203.0.113.5")` 得到 `203.0.113.5`。`2001:db8::1` 仍返回原样（或仅 trim）。不要改 `config.json.example` 的监听。
- **V6-22**：文档写明先看 `sysctl net.ipv6.bindv6only`，不是 0 就不要改监听。回滚是把 `REDI_HTTP_LISTEN` / `REDI_TLS_LISTEN` 设回 `0.0.0.0:80` 和 `0.0.0.0:443` 后重启 `redi-multi`。
- **V6-23**：只加注释。

---

## 5. PR 说明

```text
范围：V6-0N（docs/IPV6-DUALSTACK-DEV.md）
验证：bash scripts/dev-preflight.sh → 退出码 0（PHPUnit N / assertions M）
未做：下一编号；设计文档第 7 节
```
