# IPv4 / IPv6 双栈 — 实施说明（Composer 2.5）

设计与不做项见 [IPV6-DUALSTACK-DEV.md](IPV6-DUALSTACK-DEV.md)。本文只回答：**谁来做、一次做多少、做完怎么在本机证明、不要用 GitHub Actions 配额。**

---

## 1. 给 Composer 2.5 的工作方式

一次会话只发 **一个** PR 编号（V6-01 … V6-16）。提示词里写明：

1. 只改设计文档该行点名的文件；测试文件算在内
2. 不要实现下一行，即使「顺便能做」
3. 不要改 IPv4 函数的判断条件
4. 做完在仓库根目录跑下面第 3 节的命令，把退出码贴进 PR 说明
5. 不要打开 GitHub Actions、不要 Re-run failed jobs、不要为了看 CI 反复 push

Composer 2.5 容易在同一次改动里「顺手抽象」。如果它开始把 `isValidPublicIpv4` 和 IPv6 收成一个函数，停掉这次提交，退回只加平行函数的版本。

建议的会话开头（把 `V6-0N` 换成当前行）：

```text
按 docs/IPV6-DUALSTACK-DEV.md 的 V6-0N 实现，只做这一行。
约束：不改 IPv4 行为；不提前做下一行；验证用本地 bash scripts/dev-preflight.sh，不要使用 GitHub Actions。
PR 说明里写上你实际跑过的命令和 PHPUnit/Go 计数。
```

分支名：`cursor/ipv6-v6-0N-<短词>-bb20`（全小写）。一个编号一个 PR，合进 `main` 再开下一个。

---

## 2. 顺序

必须按编号做。V6-07 之前不要改 `DnsProviderInterface`。V6-12 之前 `syncAll` 不能调用 AAAA。V6-08～V6-11 可以在 V6-07 合并后逐个做，不要四个厂商一次提交。

可以停的安全点：

| 停在 | 已经有什么 | 还没有什么 |
|------|------------|------------|
| V6-06 合并后 | 后台能保存同一节点的 v4 + v6 | DNS 仍只有 A |
| V6-13 合并后 | API 同步和手动参考表都有 AAAA | 预检默认仍不看 AAAA |
| V6-16 合并后 | 第一期代码结束 | lagran 仍只处理 IPv4；控制面仍只走 IPv4 |

---

## 3. 验证（不用 Actions 配额）

每一行合并前在 **开发机仓库根目录** 执行：

```bash
bash scripts/dev-preflight.sh
```

这会跑 Go 测试、PHPUnit、CentOS 脚本语法和 SQL 存储过程对照。本机没有 Docker 时，脚本会跳过 MariaDB 容器导入；这可以接受，不要为此去点云端 `centos7-integration`。

只改了单个测试类、想先看失败时，可以缩小范围，但 **开 PR 前仍要跑完整 preflight**：

```bash
cd master/master && ./vendor/bin/phpunit --filter RediNode
cd node/redi-multi && go test ./...
```

V6-15 若只动 Go 配置测试，preflight 里的 `go test ./...` 已经覆盖，不必再起 CentOS 虚拟机。

真机（CentOS 7.9）只在 **V6-16 合并之后** 抽测，不在每个小 PR 上做：

1. 后台同一节点：主 IPv4 + 主 IPv6
2. DNS 厂商里 pool 同时有 A 和 AAAA；删掉 IPv6 后再同步，AAAA 消失、A 还在
3. 节点 `REDI_HTTP_LISTEN=[::]:80`、`REDI_TLS_LISTEN=[::]:443` 后，从 v4 和 v6 客户端都能连上
4. `NODE_IPS` 仍然只填节点的 IPv4，节点到主控的 MySQL 与 Agent 仍通

---

## 4. 每一行怎么验收

### V6-01 / V6-02

单测直接调用静态方法。用例至少包括：

- `2001:db8::1` 通过，并变成小写
- `::ffff:192.0.2.1`、`[2001:db8::1]`、`fe80::1%eth0`、`1.2.3.4` 失败
- 列表去重

### V6-03 / V6-04 / V6-06

用现有 sqlite Feature/Unit 的建表方式。迁移要能在空库 `migrate` 加上两列。`publicIps()` 的旧用例一个都不能改期望值。

### V6-05

后台帮助文字写明：IPv6 可选；一行一个；不要把 IPv6 填进「其他公网 IP」（那一栏仍是 IPv4）。不填时保存路径与现在相同。

### V6-07

接口方法签名：

```php
/**
 * @param  string[]  $ipv6
 */
public function upsertAAAARecords(string $fqdn, array $ipv6): void;
```

这一次五家方法体为空（或只 `filterValidIpv6List` 后 return）。测试里的匿名类补上同名空方法，否则 PHPUnit 起不来。`EdgeDnsSyncService::syncAll` 仍不调用它。

### V6-08～V6-11

对照该厂商已有的 `upsertARecords`：列出已有 AAAA、补缺、删掉不在列表里的。不要改 A 的代码路径。单测用假 HTTP，不要打真实厂商。

### V6-12

测试注入的假 Provider 记录调用顺序：先 `upsertARecords`，再 `upsertAAAARecords`。A 抛错时 AAAA 调用次数为 0。IPv6 为空数组时 AAAA 仍调用一次。

### V6-13

参考表在有 IPv6 时多出类型 `AAAA` 的行；全是 IPv4 时行集与现在一致。

### V6-14

默认配置 false。单测两支：false 时只有 AAAA、没有 CNAME/A 则预检失败；true 且 AAAA 等于某启用节点的 IPv6 则通过。

### V6-15

只证明环境变量能把监听地址设为 `[::]:80`。在 `DEPLOY-centos7.md` 加一小节：这样监听才同时接受 v4/v6；lagran 仍只处理 IPv4，IPv6 访客没有 NFQUEUE。不要改 `lagran.go`。

### V6-16

`vars.example.env` 用注释说明 IPv6 填后台节点，不写进 `NODE_IPS`。不要放宽 `redi_is_ipv4`。

---

## 5. PR 说明里要有的三行

```text
范围：V6-0N（见 docs/IPV6-DUALSTACK-DEV.md）
验证：bash scripts/dev-preflight.sh → 退出码 0（PHPUnit N / assertions M）
未做：下一编号及文档第 2 节「明确不做」
```

GitHub Actions 若因配额失败，不构成这一行的失败条件。本地 preflight 才是合并依据。
