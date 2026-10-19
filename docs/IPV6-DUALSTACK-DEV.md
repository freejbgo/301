# IPv4 / IPv6 双栈 — 开发设计

**状态：** 规划。`main` 在写本文时 **只支持 IPv4**。本文约束后续实现，不表示功能已上线。

**读者：** 用 **Composer 2.5** 逐个 PR 实现的人。每个 PR 必须自洽，不能依赖「模型记得上一轮」。

**配套：** 实施步骤、PR 提示词和本地验证见 [IPV6-DUALSTACK-IMPLEMENTATION.md](IPV6-DUALSTACK-IMPLEMENTATION.md)。

---

## 1. 现状（不要假设已经支持）

| 面 | 行为 |
|----|------|
| 后台节点 | `public_ip` + `extra_ips` **只接受 IPv4**。`RediNode::isValidPublicIpv4` / `parseExtraIps` 拒绝其他地址 |
| 同一节点多个地址 | **多个 IPv4** 可以：主 IP +「其他公网 IP」。仍是 **一个进程、一个 Token** |
| 同一节点 IPv4 + IPv6 | **不行**。没有 IPv6 字段，也没有 AAAA |
| DNS | `DnsProviderInterface::upsertARecords` 只写 **A**。Cloudflare / 阿里云 / DNSPod / dns.com / 手动模式都没有 AAAA |
| 启用预检 | CNAME 到 edge/pool，或（开关打开时）客户域 **A** 命中 pool IPv4。没有 AAAA 预检 |
| 部署 | `MASTER_IP`、`NODE_PUBLIC_IP`、`NODE_IPS`、MySQL 授权、firewalld 3306 全部 `redi_is_ipv4` |
| 节点数据面 | 默认监听 `0.0.0.0:80` / `0.0.0.0:443`（IPv4）。`RemoteAddr` 解析能认出 `[2001:db8::1]:443` 这种文本，**不代表**平台会把 IPv6 写入池 |
| lagran | iptables **NFQUEUE**，只覆盖 IPv4。IPv6 流量不会进这条链 |

控制面文档里的「yum 优先 IPv4」只是安装时避开坏掉的 IPv6 出口，与业务双栈无关。

---

## 2. 目标（第一期就做这些）

一台边缘机可以同时有公网 IPv4 和 IPv6。后台 **同一条节点** 登记：

- 已有：主 IPv4、其他 IPv4
- 新增（可选）：主 IPv6、其他 IPv6

Pool DNS：

- IPv4 列表继续写 **A**（行为与现在一致，IPv6 工作不得改变 A 的内容）
- IPv6 列表写 **AAAA**；列表为空时清掉 pool 上由本系统管理的 AAAA（与 A 的「先增后删」同一语义）

客户启用预检：

- 现有 CNAME / A 逻辑保持默认
- 增加可选「AAAA 命中 pool IPv6」；**默认关闭**，避免没配 IPv6 的环境被误判

节点进程：

- 操作员把 HTTP/TLS 监听改成 `[::]:80` / `[::]:443` 时，Linux 上 Go 默认双栈接受 v4+v6
- **不**在第一期改 lagran / ip6tables

明确不做（另开主题，不要塞进下面任一 PR）：

- 节点用 IPv6 连主控 MySQL、`NODE_IPS` 改成 v6、firewalld 对 v6 放行 3306
- 主控 Nginx / 后台本身的 IPv6 监听
- lagran 的 ip6tables
- 把 IPv4 与 IPv6 收成一个「通用 IP」大重构（Composer 2.5 容易改坏现有 IPv4 校验）

**控制面保持 IPv4：** Agent、`REDI_MASTER_URL`、MySQL `redi_node` 仍走 IPv4。双栈只覆盖 **访客 → 节点 80/443** 和 **pool 的 A + AAAA**。

---

## 3. 数据

不复用 `public_ip` / `extra_ips` 存 IPv6。两套字段，两套校验。

| 字段 | 类型 | 含义 |
|------|------|------|
| `public_ipv6` | `string`，可空，最长 45 | 主 IPv6，压缩格式、无方括号、无 zone id |
| `extra_ipv6` | JSON 数组，可空 | 同一节点上的其他公网 IPv6 |

规则：

- 主 IPv6 可空。只有「其他 IPv6」、没有主 IPv6 时拒绝（与「其他 IPv4 依附主 IPv4」不同：IPv4 主地址今天是必填；IPv6 整组可选，但一旦填了附加地址就必须有主地址）
- 拒绝 IPv4 映射地址（`::ffff:192.0.2.1`）
- 拒绝带 `%` 的 zone、带 `[]` 的文本
- 比较前转小写
- IPv6 只跟其他节点的 IPv6 查重，不跟 IPv4 混在一个集合里
- `publicIps()` / `enabledPublicIps()` **继续只返回 IPv4**。新增 `publicIpv6s()` / `enabledPublicIpv6s()`

---

## 4. 为什么要拆这么细

Composer 2.5 适合：**一个已有函数旁边加一个平行函数、改完跑一条点名的测试**。不适合：同一次改接口、四家 DNS、同步服务、后台表单和防火墙。

硬限制（写进每个 PR 的提示词）：

- 一次只做下表 **一行**
- 除了该行点名的文件和它的测试，不要改别的
- 不要顺手重命名、不要换 DNS 库、不要改 IPv4 校验的语义
- 逻辑改动尽量落在 150 行以内；超了就停，把剩余拆到下一行，不要在同一次会话里硬做完
- 验证只用仓库本地命令（见实施文档）。**不要**等 GitHub Actions，也不要为了验证去点 Re-run workflow

---

## 5. PR 序列

依赖只能向下。某一行测试红了就停在该行，不要跳去做后面的厂商。

| ID | 只做什么 | 主要文件 | 完成标准 |
|----|----------|----------|----------|
| V6-01 | `isValidPublicIpv6`：接受压缩地址，拒绝映射地址、方括号、zone、IPv4 | `RediNode.php` + 新单测 | 现有 IPv4 测试仍绿 |
| V6-02 | `filterValidIpv6List`：去空、去重、小写 | 同上 | 不读数据库 |
| V6-03 | 迁移 `public_ipv6`、`extra_ipv6`；`$fillable` / cast；`publicIpv6s()` 读这两列 | 新 migration、`RediNode.php`、单测 | `publicIps()` 用例结果不变 |
| V6-04 | `parseExtraIpv6` + `assertIpv6Available` | `RediNode.php`、单测 | 错误文案写明「IPv6」 |
| V6-05 | 后台两个可选字段；保存时走 V6-04 | `RediNodeController.php`、单测或 Feature | 不填 IPv6 时与现在相同；填 `2001:db8::1` 能存 |
| V6-06 | `enabledPublicIpv6s()` | `RediNode.php`、单测 | 停用节点不出现 |
| V6-07 | 接口增加 `upsertAAAARecords`；五家实现 + 测试里的匿名类先做 **空操作** | `DnsProviderInterface.php`、五家 Provider、`EdgeDnsSyncServiceTest.php` | `syncAll` 仍只调用 `upsertARecords` |
| V6-08 | 仅 Cloudflare：按现有 A 的先增后删实现 AAAA | `CloudflareDnsProvider.php` + 其单测 | 其他厂商仍是空操作 |
| V6-09 | 仅阿里云 AAAA | `AliyunDnsProvider.php` + 单测 | 同左 |
| V6-10 | 仅 DNSPod AAAA | `DnspodDnsProvider.php` + 单测 | 同左 |
| V6-11 | 仅 dns.com AAAA | `DnsComDnsProvider.php` + 单测 | 同左 |
| V6-12 | `syncAll` 在 A 成功之后调用 `upsertAAAARecords(enabledPublicIpv6s())`；日志加上 AAAA 条数 | `EdgeDnsSyncService.php` + 其测试 | IPv6 列表为空也要调用（让厂商删掉旧 AAAA）；A 失败时不要调用 AAAA |
| V6-13 | 手动模式参考表增加 AAAA 行 | `EdgeDnsManualReference.php` + 单测 | 没有 IPv6 时不出现 AAAA 行 |
| V6-14 | 预检：配置 `redi.dns_precheck_allow_aaaa_to_pool` 默认 **false**；为 true 时 AAAA 命中 pool IPv6 算通过 | `config/redi.php`、`CnamePrecheckService.php`、单测 | 默认 false 时现有用例不变 |
| V6-15 | 节点：单测锁定 `REDI_HTTP_LISTEN=[::]:80` 能写入监听地址；文档说明 CentOS 7 上这样才是双栈，以及 lagran 不管 IPv6 | `config` 测试、`DEPLOY-centos7.md` 短节 | 不改 iptables |
| V6-16 | `vars.example.env` 注释：IPv6 只填后台，**不要**写进 `NODE_IPS` | `vars.example.env`、本文状态改成「代码侧按表落地后勾选」 | 不改 `redi_is_ipv4` |

V6-07 必须一次改完所有实现了接口的类，否则 PHP 直接起不来。方法体保持空，不要在这一次里写 HTTP。

---

## 6. 不变量

- 未填任何 IPv6 的节点，A 记录、预检、部署脚本与现在一致
- `original_url` 仍然不能是 IP（含 IPv6）。客户域名还是主机名
- Token、证书下发、revision **不**因为多了 IPv6 而改变哈希字段，除非以后单独提案
- AAAA 同步失败的报错要写明「AAAA」，并且说明 A 可能已经写到厂商（与现有 CNAME 失败文案同一风格）
