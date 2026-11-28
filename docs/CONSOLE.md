# 控制台操作手册

后台地址是 `https://主控域名/admin`。安装见 [DEPLOY-BEGINNER-CENTOS7.md](DEPLOY-BEGINNER-CENTOS7.md)。客户侧只需要做的 CNAME 说明见 [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md)。

初始账号 `admin` / `Redi301@Install2026`。仍为默认密码时，登录后会 **强制进入改密页**（`Admin → 用户设置` / `auth/setting`），改完后才能使用其它菜单。内网演示可在主控 `.env` 设 `REDI_BLOCK_DEFAULT_ADMIN_PASSWORD=false`。

## 界面语言（简体中文 / 繁體中文 / English）

- **切换**：登录页里，登录按钮下面单独一行是语言下拉框。登录后在顶栏右侧点地球图标，从下拉菜单里切换。登录前选的语言，登录后仍然有效。
- **默认**：还没选过时跟随浏览器。简体用简体中文，繁体用繁体中文，其它语言用英文。
- **记忆**：选择会写入浏览器 session 和 cookie；**登录后**会写入管理员账号的 `locale` 字段，下次登录没再改语言时自动沿用（需已执行 `php artisan migrate`）。
- **系统外观**：在 **站点配置 → 系统外观** 按 **简体中文 / 繁體中文 / English** 三个选项卡分别填写名称与标题；选项卡下方上传 Logo、Favicon，全平台共用。安装后这两项是空的，侧栏只显示系统名称，浏览器不带站点图标。导入 SQL 后直接用 Tab 填写即可。若从外部拷贝的配置仍是**仅顶层**品牌 JSON，换包时见 [OPS-RELEASE-UPDATE.md](OPS-RELEASE-UPDATE.md) 的 `redi:normalize-platform-branding`。未保存过时，表单与各语言界面会显示语言包默认品牌名。
- **侧栏菜单**：业务项与 `Admin` 子菜单优先按库内 title 映射；若 title 无翻译键，则按菜单 URI（如 `group`、`auth/users`）回退到语言包。
- **证书 `last_error` 列**：展示库内运维说明（**不随界面语言翻译**）；新记录一般为英文。CLI/Webhook 亦为英文。详见 [I18N.md](I18N.md)。

## 菜单

| 菜单 | 谁能用 | 做什么 |
|------|--------|--------|
| 域名管理 | 每个登录账号，只看自己的域名 | 首页也进这里 |
| 站点配置 → 分组管理 | 每个账号，只看自己的分组 | 给域名分组，按组改跳转地址 |
| 站点配置 → 域名管理 | 同上 | 添加客户域名、看 CNAME、申请证书 |
| 站点配置 → 系统外观 | 仅超级管理员 | 各语言的名称与标题，以及全平台 Logo、Favicon |
| 站点配置 → DNS 与节点池 | 仅超级管理员 | 平台主域名、手动参考表或 API 同步 |
| 站点配置 → 节点管理 | 仅超级管理员 | 节点 IP、启用、Token |
| 站点配置 → Lagran 设置 | 仅超级管理员 | 全部节点共用的 lagran 开关和窗口 |
| Admin → Users / Roles | 超级管理员 | 后台登录账号 |

普通账号加域名之前，超级管理员需要先把平台域名、pool 和至少一台启用节点配好。否则 CNAME 目标是空的，证书也无法签发。把 CNAME 步骤交给终端客户时，可只转发 [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md) 前半部分（「您需要做的」至「HTTPS」）；Pool 同步失败等运维问题见本文 §2，勿让客户自行改 Pool。

## 1. 系统外观

**站点配置 → 系统外观。**

可改各语言下的系统名称、浏览器标题、登录框标题。Logo（展开侧栏、收起侧栏）和 Favicon 在语言选项卡下面，三个语言共用。没上传之前不显示图片。图片存在 `storage/app/public/branding`，页面通过 `/storage/...` 访问，服务器上要已经执行过 `php artisan storage:link`。品牌设置全平台一套，不是每个租户账号各一套。

页脚不再显示 Dcat 的版权行。

## 2. DNS 与节点池

**站点配置 → DNS 与节点池。** 页面说明的是模型 2：客户域 CNAME 到租户 token，token CNAME 到 pool，pool 的多条 A 指向节点 IP。

填写：

| 字段 | 填写 |
|------|------|
| 平台主域名 | 你拥有解析权的域名，如 `123.com`。不要填客户的域名 |
| Pool 主机名 | 默认 `pool`，最终是 `pool.123.com` |
| 解析模式 | 手动，或 API 自动 |
| DNS 驱动 | 手动模式保持「手动」。API 模式选 Cloudflare、阿里云中国/国际、DNSPod 中国/国际或 dns.com，不能选「手动」 |
| Zone ID | Cloudflare 必填，其他厂商可空 |
| API Key / Secret | 按字段说明填写。留空表示不修改已保存的密钥 |
| ACME 联系邮箱 | Let's Encrypt 账户邮箱 |
| ACME 测试环境 | 联调时打开。正式签发要关闭 |

改了平台主域名并保存后，已有租户的 `{token}` 主机名会按新域名重算。

### 手动模式

没有「同步」按钮。下面两张表是抄到 DNS 厂商控制台的参考：

- **Pool**：类型 A，主机名是 pool 的 FQDN，记录值是每个启用节点的公网 IPv4。没有启用节点时，表里会提示先去节点管理添加。
- **租户 edge**：类型 CNAME，主机记录是该账号的 token，指向 `pool.平台主域名`。有人登录并添加过域名之后才会出现具体 token。

在厂商改完记录即可。本系统不会调用 DNS API。

### API 自动

保存驱动和凭证后，点 **同步 Pool / 租户 CNAME 到 DNS 厂商**。同步会按节点列表 **先增/改、后删** pool 上的 A 记录，再写入各租户 token 指向 pool 的 CNAME；**全部租户 CNAME 成功** 后才更新库里的 `dns_synced_at`。任一步失败时页面会显示厂商返回的原因，且 `dns_synced_at` **不会**前进（厂商侧可能已部分写入，修正后请再点同步）。若租户 `edge_fqdn` 与当前平台主域名不一致，同步会 **失败** 并提示修正（全部不一致时不会改厂商 DNS；部分不一致时 Pool A 可能已更新）。

添加、删除或改了节点 IP 之后要再同步一次。删除节点不会自动删除厂商上的 A 记录。DNS 凭证解密失败会记日志并中止同步（不会静默跳过）。

#### 同步失败后的检查（API 自动）

1. **看后台报错全文**（厂商 API 返回原因），不要只看「未同步」状态。
2. **到 DNS 厂商控制台核对**：Pool 的 **A / AAAA** 是否已改、各租户 **token → pool** 的 CNAME 是否只写了一部分（失败时 `dns_synced_at` 不会前进，但厂商侧可能已部分更新）。
3. **主控自检**（不影响业务退出码，只看提示）：
   ```bash
   cd /opt/301/master
   sudo -u nginx php artisan redi:health --json | jq '.hints[] | select(.code=="dns_api_tenants_unsynced")'
   ```
4. **常见修正**：租户 `edge_fqdn` 与当前 `edge_zone` 不一致；Cloudflare 缺 Zone ID；凭证过期；Pool 无启用节点 IPv4（同步会先失败，不调厂商）。
5. **修正后再次点击「同步 Pool / 租户 CNAME」**；系统**不会**自动回滚厂商上已写入的记录。

域名在列表里设为 **停用** 时，主控会清除该域的 ACME 挑战与节点挑战同步行，减少节点继续暴露 HTTP-01。

## 3. 节点管理

**站点配置 → 节点管理。** 一条节点是一台机器、一个进程、一个 Token。

新建：

| 字段 | 填写 |
|------|------|
| 名称 | 便于识别的名字 |
| 主公网 IP | 后台维护的这台机器主 IPv4；`config.json` 的 `server_ip` 建议与之相同（供验收对照，程序不读取） |
| 其他公网 IP | 同一台机器上还要写入 pool 的 IPv4，一行一个。仍然只用这一个 Token |
| 启用 | 关闭后，这台机器的全部 IP 都不再进入 pool，证书签发也不再等它 |
| 备注 | 可选 |

保存后回到节点列表。列表下方是安装命令，只把最后的 `节点Token` 换成节点「查看 Token」里的那一串。英文界面里这一处是 `Node Token`，繁体界面是 `節點Token`。`--install-key` 后面是主控安装密钥，不是节点 Token。操作菜单里的「查看 Token」会直接给出已经填好 Token 的整条命令。更早创建、没有留下 Token 的节点，用同一菜单里的「重置 Token」生成新的，旧 Token 立即失效。

列表里的「启用」开关只改启用状态，不会清空 IP。公网 IP 列会把主 IP 和其他 IP 都显示出来。

不要把同一台机器的每个 IP 另建成节点。那样每条额外节点都要有自己的进程和 Token，没有进程的那条会让证书一直等下去。

节点程序不能在连上主控后自行修改 IP 或名称。IP 只在这个页面改。改完 IP 后，手动模式去厂商改 pool 的 A，API 模式再同步一次。

最后心跳超过一段时间没有更新，说明进程没在跑、Token 不对，或主控地址 `REDI_MASTER_URL` 不对。

### Lagran 设置

**站点配置 → Lagran 设置。** 只超级管理员。这里是全部节点共用的一份设定：开关、80 和 443 各自的窗口、纯 ACK 计次，以及计满之后的窗口（默认 28960）。

保存后，在线节点大约 5 秒内取走，写回自己的 `config.json`，并在本进程里重新加载。不用登录每台机器，也不用把节点进程整个重启。本机环境变量 `REDI_LAGRAN_DISABLED=1` 仍然强制关闭。队列号不在这个页面改。更早的节点程序不会读取这份设定，继续用本机文件。

### 节点管理（仅超级管理员）

勾选行后可 **批量启用 / 批量停用 / 批量重试失败证书 / 批量重置 Token**（Token 单次最多 20 台，成功提示中含明文，须立即写入各节点 `REDI_NODE_TOKEN`）。启用/停用/重试单次最多 **100** 台节点，超出部分会在成功提示中说明。工具栏 **重试全部失败部署** 仍作用于全平台 failed 记录。

## 4. 分组管理

**站点配置 → 分组管理。** 普通账号只看见自己的分组；**超级管理员** 可看见全部租户的分组。

新建时填写名称和状态（启用 / 停用）。**超级管理员** 新建分组时必须选择 **所属租户账号**（选了该分组的域名归属该租户，CNAME 目标也按该租户计算）。租户账号自己新建分组时无需选租户。添加域名时分组可以不选；选了就只能选本账号下的分组。

分组下还有域名时不能删除。域名列表里如果分组已被删掉，分组名称显示「（分组不存在）」。

**批量修改跳转**：某一行上的这个操作，把该分组下你名下的域名跳转地址改成同一个 URL。地址必须以 `http://` 或 `https://` 开头。成功后会写入批量审计日志（`url_set_by_group`，含更新/跳过条数，不记 URL 正文）。

勾选多行后可 **批量启用 / 批量停用 / 批量删除** 分组（删除仅当分组下没有域名）。单次最多 **200** 条，超出部分会在成功提示中说明；勾选批量无「须先搜索」类筛选守卫，请勿一次勾选过多行。

## 5. 域名管理

**站点配置 → 域名管理**，或点左侧 Index。只显示当前账号的域名。

### 添加一条

| 字段 | 填写 |
|------|------|
| 分组 | 可不选。不选则列表显示「未分组」。选了就用本账号的分组 |
| 原始域名 | 客户访问的主机名，如 `foo.com` 或 `www.foo.com`。不要写 `http://` |
| 跳转链接 | 跳过去的完整地址，必须以 `http://` 或 `https://` 开头 |
| 状态 | 启用才会跳转并统计次数。停用后不再跳转，也不增加跳转次数 |
| 强制 HTTPS | 打开后，节点在 **已下发并安装该域名 TLS 证书** 时才会对 HTTP 301 到 HTTPS；证书未就绪时不要开 |

`foo.com` 和 `www.foo.com` 是两条记录，各要一张证书。同一账号不能添加两条相同的原始域名。

表单上方有本账号的 CNAME 目标，可以复制。这个目标对该账号下所有域名都一样。

### 列表上要看的列

| 列 | 含义 |
|----|------|
| CNAME 目标 | 交给客户去解析的值，形如 `abcd1234.123.com` |
| 解析预检 | 「已指向」表示这个客户域名已经 CNAME 到该目标。结果缓存大约一分钟 |
| 证书 | 未申请、待解析、验证中、已签发、部署中、已生效、失败。失败时红色文字是原因 |
| 跳转次数 | 访客在 **HTTP 或 HTTPS** 打开客户域名、页面加载 `/api.js` 且该域名在后台为 **启用** 时加 1（两种协议统计方式相同，各算一次访问）。仅被 **强制 HTTPS** 从 HTTP **301 到 HTTPS** 的那一步不计数；到了 HTTPS 页面后仍会按上面规则计数。未在后台登记的域名走默认跳转时 **不** 增加次数。由节点写库，不是手填 |

筛选可以按分组、原始域名、跳转地址、状态。

### 批量添加域名

工具栏 **批量添加域名**。选择分组后，文本框一行一条，最多 500 条有效行。空行和以 `#` 开头的行忽略。

- 只写原始域名时，要在上面填统一跳转地址。
- 或者每行写 `原始域名,跳转地址`。也可用 Tab 或竖线分开。
- 本账号已存在的原始域名会跳过。

点进输入框后，灰色占位文字会消失，这是浏览器的正常行为。

### 批量操作（勾选行后使用工具栏「批量」菜单）

| 操作 | 说明 |
|------|------|
| 批量启用 | 将选中域名设为启用；须满足与单条编辑相同的 CNAME/解析校验（平台管理员可跳过） |
| 批量停用 | 停用后不再跳转，进行中的证书部署会取消 |
| 批量修改 | 弹窗可改跳转链接、分组、强制 HTTPS（留空/不选表示该项不改） |
| 批量申请证书 | 仅为 **启用** 且你有权限的域名入队 |
| 重新下发证书 / 批量重新下发 | 普通账号和超级管理员都能用。只处理自己名下、已签发且证书文件可读的域名，向全部启用节点重新排队。超级管理员可处理全部租户 |
| 按筛选批量 | 含申请证书、**重新下发**、启用、停用、删除、修改跳转地址、**开启强制 HTTPS**（仅证书已生效的域名会打开，其余在结果中说明）、**关闭强制 HTTPS**（关掉已经打开的，不要求证书已生效）；**未勾选且未带任何列表筛选时后台会拒绝**（防误点全表敏感操作）；单次最多 500 条，超出部分会在成功提示中说明。先点「搜索」再打开弹窗 |

工具栏另有 **批量添加域名**（与勾选无关）和 **导出 CSV**。

**批量审计**：域名/分组/节点的勾选批量、按筛选批量、**批量添加域名**、批量修改等成功执行后，会写入 `storage/logs/admin-batch.log`（JSON 行，含操作者、动作、条数、ID 抽样；不含 Token 明文与批量导入的域名列表正文）。

### 申请证书

一行右侧的 **申请证书**，或勾选多行后的 **批量申请证书**。只能申请自己名下的域名。

申请前要满足：

- 主控上的队列在跑（CentOS：`systemctl is-active redi-queue`；参数见 [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md) / `scripts/systemd/redi-queue.service.example`）。
- 平台主域名、ACME 邮箱已保存。正式环境不要开 ACME 测试环境。
- 客户域名的解析预检为「已指向」。
- 所有 **启用** 的节点在线，并且已经用 Token 连上主控。Let's Encrypt 访问客户域名时可能落到池里任意一个 A 记录，所以每台启用节点都要能回答验证。

状态含义：

| 状态 | 说明 |
|------|------|
| 待解析 | CNAME 还没指向平台，任务不会反复重试，解析好之后再点申请 |
| 验证中 | 主控正在等待各节点同步 HTTP-01，并向公网客户域名探测。节点上报的 `challenges-synced` **超过约 3 分钟** 视为过期，不会当作「已同步」；长期停在此状态可查 `cert_validating_stuck`（见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)）与各节点 Agent 拉取挑战是否成功 |
| 部署中 | 证书已签发，还在等节点安装。有节点离线时会停在这里 |
| 已生效 | 至少一台在线节点已经安装。若有节点失败，下面仍会显示错误，该节点下次还会重试 |
| 失败 | 在线节点都报告安装失败，或签发出错。看红色原因 |

签发时同一时间只有一个 ACME 任务在跑。批量申请会排队。每天 03:10 的计划任务会给即将过期的证书续期，客户不用改 DNS。

强制 HTTPS 等「已生效」后再打开。

## 6. 建议的操作顺序

1. 首次登录完成 **强制改密**（或已改掉种子密码）。需要别人登录时，在 **Admin → Users** 建账号。
2. 系统外观按需要改一次。
3. DNS 与节点池填写平台主域名，选手动或 API。
4. 节点管理添加机器，Token 配到对应节点并确认有心跳。
5. 按模式把 pool 的 A 和租户 CNAME 做到 DNS 厂商。
6. 分组管理建分组，域名管理添加客户域名，把 CNAME 目标发给客户。
7. 预检「已指向」后申请证书，生效后再开强制 HTTPS。

以后加一台机器：主控 `vars.env` 的 `NODE_IPS` 加上新出口 IP 后跑 `deploy-master.sh` 或 `grant-nodes-from-vars.sh`，节点管理新建一条并下发 Token，再更新 pool 的 A。给现有机器加 IP：只改那一条的「其他公网 IP」，然后更新 pool 的 A。

## 7. 运营自检（主控 SSH）

日常与 Cron 使用（在 `master/master` 或发布目录 `/opt/301/master`）：

```bash
php artisan redi:health
php artisan redi:health --json    # 监控采集；summary 含队列/validating 阈值；issues/hints 的 code 见 HEALTH-REFERENCE.md
php artisan redi:prune-acme-challenges   # 标准输出含 pruned expired=…, invalid_empty=…, sync_rows=…
php artisan redi:health --notify  # 有 issues 时 POST webhook（Cron 08:30；发送成功退出 0）
php artisan redi:renew-certificates --dry-run --days=25   # 续期预演；正式 Cron 跑无 --dry-run，入队后日志含 queued: N cert(s)（见 renew.log）
php artisan redi:requeue-failed-deployments   # 可选 --node= / --cert=
php artisan redi:normalize-domain-hosts --dry-run   # 历史 IDN / skip_url 修正；无效 skip 会清空并告警
# php artisan redi:ops-webhook --task=redi:renew-certificates --message="测试"
# DNS API 同步（超管后台）；dns_driver 拼写错误会在工厂层报错，勿与手动模式混淆
```

`issues` 非空时命令退出码为 1，适合告警；`hints` 不影响退出码。CentOS 生产续期/清理/health 仅由 `/etc/cron.d/redi-renew` 触发，验收 `grep` 见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)。Cron 写入 `storage/logs/renew.log`、`prune-acme.log`、`health.log`（排障续期/清理/health 失败）。Webhook 与多 worker 说明见 [OPS-MULTI-WORKER-QUEUE.md](OPS-MULTI-WORKER-QUEUE.md)；health 各 `code` 见 [HEALTH-REFERENCE.md](HEALTH-REFERENCE.md)。

## 8. 平台边界与运营约定

架构与节点 API 表见 [PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)。本节是后台与运维常用的「能做什么 / 不能做什么」。

### 不提供

- NS 委托、多节点池（多 pool）、逐域 TXT 验证书主流程  
- 客户自带 DNS API、由主控替代 MySQL 提供跳转规则  

### 产品行为（非缺陷）

- 跳转页为 **HTTP 200 + JS**，不是业务层 301；**强制 HTTPS** 在节点 **已有该域名 TLS 证书** 时对 HTTP **301** 到 HTTPS。  
- 「抢注」指平台内 `original_url` 唯一。新建可以直接保存，列表里复制 CNAME 再去解析；解析是否生效看「解析预检」，与注册商抢注无关。  

### 运营注意

| 项 | 说明 |
|----|------|
| Let's Encrypt | 批量申请会排队；全局同时只跑一个 ACME 任务 |
| 主控队列 | `QUEUE_CONNECTION` 须 **database** 或 **redis**，勿长期 `sync` 跑批量 ACME |
| HTTP-01 | `/.well-known/` 必须优先于跳转（与 lagran 联调） |
| 根域 CNAME | 部分厂商不支持；见 [CUSTOMER-CNAME-HTTPS.md](CUSTOMER-CNAME-HTTPS.md)（ALIAS/ANAME） |
| DNS API 部分失败 | 厂商可能已写入部分 Pool/CNAME；按上文 §2「同步失败后的检查」修正后重试 |
| 长期限制 | [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) §已知限制 |
