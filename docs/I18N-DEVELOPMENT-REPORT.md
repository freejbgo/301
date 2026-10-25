# 301 跳转系统 — 多语言（简 / 繁 / 英）开发报告

**版本**：2026-10-25（阶段 27：Admin 配置回退 + 运维英文收口）  
**范围**：主控 `master/master/` 管理后台**可见操作界面**（Laravel + Dcat Admin）；仓库 `docs/` 运维手册仍为中文。  
**产品目标（已定）**：让使用后台的**超级管理员与租户账号**在 **简体中文 / 繁体中文 / English** 下方便操作；库内历史 `last_error` 不要求翻译。  
**结论摘要**：自研 Admin 可见 UI 已三语化（`redi-*`、Dcat `menu`/`global`/`extension`、顶栏/登录切换、`PlatformBranding` per-locale）。**运维层**：新写入的 `last_error`、Artisan、Webhook、`redi:health` JSON、节点 API 门控等以 **`en/redi-errors` 英文 canonical** 为主；后台仍可能展示库内旧中文 `last_error` 原文。

### 实施进度（代码）

| 阶段 | 状态 | 内容 |
|------|------|------|
| 1 | **已做** | `SetAdminLocale` 中间件、顶栏切换、`en/menu.php` / `zh_TW/menu.php` 业务菜单键、`locale` 修正为 `zh_CN` |
| 2 | **已做** | 域名管理列表/表单/校验、证书状态标签、批量弹窗 |
| 3 | **已做** | 首页（超管+租户）、分组管理、批量导入域名表单 |
| 4 | **已做** | 域名/节点 Grid 行操作、节点列表、默认密码 Toast、`trans()` 修复批量状态选项 |
| 5 | **已做** | DNS 与节点池、系统外观、节点创建/编辑表单、分组批量跳转、`redi-auth.admin_only` |
| 6 | **已做** | `EdgeDnsManualReference` 占位三语、首页 `RediHealthReport` 告警 `redi-health` |
| 7 | **已做** | `collectHints` 三语 + 首页展示、批量重试 Toast、`en/global.php`（Dcat 字段） |
| 8 | **已做** | `en/extension.php`、`admin_users.locale` 持久化、`redi-locale` 壳层文案 |
| 9 | **已做** | 登录 `Login` 事件同步 session ↔ `admin_users.locale` |
| 10 | **已做** | 登录页内联语言切换、`zh_CN/menu.php` 业务菜单键对齐 |
| 11 | **已做** | `redi-branding` 默认品牌三语、`cert_last_error` 标签、`SetAdminLocale` 先于 `admin` 中间件 |
| 12 | **已做** | 补齐 `zh_TW` `cert_last_error`、文档进度表与 §快照 |
| 13 | **已做** | `RediLangParityTest` 覆盖 `menu`/`global`/`extension`；`global.php` 键对齐 |
| 14 | **已做** | `scripts/verify-admin-i18n-invariants.sh` 接入 CI / dev-preflight |
| 15 | **已做** | `CONSOLE.md` 语言切换运维说明；文档索引更新 |
| 16 | **已做** | `PlatformBranding` `locales` JSON、系统外观按语言 Tab、缓存 `v2`、单测 |
| 17 | **已做** | 域名列表 CSV 导出表头随 `SetAdminLocale`（`DomainListCsvExporter::headerRow`） |
| 18 | **已做** | `menu.uris.*` + `AdminMenuTitle` / `LocalizedMenu`（DB title 无键时按 URI 回退） |
| 19 | **已做** | `redi-locale.names` 驱动顶栏/登录语言切换；i18n 门禁校验 `LocalizedMenu` |
| 20 | **已做** | `menu.uris.auth.*`；CONSOLE 菜单说明；门禁校验语言切换 Blade |
| 21 | **已做** | `RediErrorMessage` + `en/redi-errors.php`；菜单 title slug 迁移；证书/批量/DNS/CLI 英文化 |
| 22 | **已做** | `redi:health` 等 Artisan 输出/webhook 英文；补齐 DNS delete_stale `redi-errors` |
| 23 | **已做** | `redi:health` issue/hint 在 CLI/JSON/webhook 固定 `en`；补齐 `ops-webhook` / renew 选项英文 |
| 24 | **已做** | 节点 API `RediAgentVersionGate` 426 响应走 `redi-errors.node_api`（英文） |
| 25 | **已做** | `RediAcmeChallenge::upsertForDomain` 校验异常走 `redi-errors.acme.*` |
| 26 | **已做** | `RediNode` / `RediAdminGroup` 模型校验走 `redi-admin-*`；平台 DNS 凭证解密文案三语 |
| 27 | **已做** | `config/admin.php` 英文文件回退（与 `en/redi-branding` 一致）；门禁禁止 config 内硬编码中文 |
| 28 | **已做** | 开发报告 / CONSOLE 与阶段 27 对齐；声明 Admin + ops 范围收口 |
| 29 | **已做** | `verify-admin-i18n-invariants.sh` 扩展校验 `app/Models` 字符串字面量无汉字 |
| 30 | **已做** | `verify-ops-english-invariants.sh` 门禁；接入 `dev-preflight` / CI |
| 31 | **已做** | DNSPod 中国 `record_line` 迁至 `redi.dnspod_cn_record_line`；`Services` 门禁无例外 |
| — | **覆盖率（粗估）** | 自研 Admin 可见 UI ~99%；运维/节点 API 新消息英文 canonical |

### 运维速览

1. 升级主控后执行 `php artisan migrate`（`admin_users.locale`）。
2. 登录页或顶栏切换语言；在 **系统外观** 各语言选项卡填写对应品牌名（旧单语数据仍有效）。
3. 开发防回退：`bash scripts/verify-admin-i18n-invariants.sh`、`bash scripts/verify-ops-english-invariants.sh`、`tests/Unit/RediLangParityTest.php`、`tests/Unit/AdminMenuTitleTest.php`、`tests/Unit/PlatformBrandingLocalesTest.php`、`tests/Unit/DomainListCsvExporterTest.php`（CI 已跑）。

### 阶段 10 后快照（相对 §2 历史审计）

| 区域 | 现状 |
|------|------|
| `app/Admin/**` 用户可见字符串 | 已走 `__` / `admin_trans` / `redi-*`（仅注释含中文） |
| `resources/lang/en` | 含 `menu`、`global`、`extension` 及全部 `redi-*` |
| 语言解析 | `SetAdminLocale`（在 `admin` 前）+ 顶栏/登录切换 + `admin_users.locale` + 登录事件同步 |
| 仍单语 / 非三语 | 证书 `last_error` 正文（历史库数据）；`docs/` 中文文档 |

---

## 1. 需求理解

| 目标 | 说明 |
|------|------|
| 可见后台三语 | 登录后**整个操作界面**：菜单、表单、表格、按钮、Toast、首页看板等随语言切换；**允许**证书列展示库内已有中文 `last_error`。 |
| 简 / 繁 / 英 | 三语并列；繁体为独立翻译文件（可与简体同键）。 |
| 非目标 | 客户 301 页、节点日志、Artisan、Webhook、仓库 `docs/` 中文文档。 |

---

## 2. 现状审计

### 2.1 语言与配置

| 项 | 现状 | 问题 |
|----|------|------|
| `config/app.php` | `locale` = `zh_CN`，`fallback_locale` = `zh_CN` | 阶段 1 已修正原 `zh_Cn` 笔误。 |
| `config/admin.php` | 文件内 `name`/`title`/`logo` 为英文回退 | 请求内由 `PlatformBranding::applyAdminConfig()` 按当前语言与库内设置覆盖。 |
| 语言目录 | `resources/lang/zh_CN`、`zh_TW`：`admin.php`、`global.php`、`menu.php`、`extension.php`；`en`：仅有框架向的 `admin.php`、`auth`、`validation` 等 | **`en` 缺少** `global.php` / `menu.php` / `extension.php`（与简繁不对齐）。 |
| 业务翻译文件 | **不存在** `redi.php`（或等价命名空间） | 全部业务文案在 PHP 源码中硬编码。 |

### 2.2 代码是否使用翻译 API

对 `app/**/*.php` 检索 `trans(`、`__(`、`admin_trans(`：**0 处**。

含义：**301 自研后台逻辑未接入 Laravel 国际化**；仅 Dcat 内置页面（用户/角色/部分扩展）在切换 `locale` 后可能显示英文。

### 2.3 中文硬编码规模（量级）

| 区域 | 约略规模 | 说明 |
|------|----------|------|
| `app/**/*.php` 含汉字 | **71 个文件**，汉字片段约 **1100+** | 含 Services（证书/DNS/健康检查）、Admin 控制器/表单/动作、Model 校验与状态文案等。 |
| `config/admin.php`、`config/redi.php` | 有中文注释与用户向默认文案 | 配置项说明、默认系统名。 |
| `resources/views` | 极少（如「回到顶部」`aria-label`） | 主体 UI 由 Dcat + PHP 拼 HTML。 |
| `database` / `db/redi_admin.sql` | `admin_menu.title` 业务菜单为中文 | 见 §2.5。 |
| Artisan 命令 | 描述与输出多为中文 | 主要面向运维；是否三语可选。 |
| 节点 Agent | API JSON `message` 多为**英文**；日志有中文 | 与后台语言无关。 |

### 2.4 按功能模块的覆盖情况

| 模块 | 简中 | 英文 | 繁中 | 备注 |
|------|------|------|------|------|
| Dcat 壳层（登录框通用按钮、部分 Admin 子菜单） | 有包 | 有 `en/admin.php` | 有 `zh_TW/admin.php` | 依赖 `locale` + Dcat 语言切换是否启用 |
| 首页 `HomeController` | 全硬编码 | 无 | 无 | 运营卡片、租户概览 |
| 域名 / 分组 / 节点 / DNS / 系统外观 | 全硬编码 | 无 | 无 | Grid 列名、filter、help、批量操作 |
| 证书状态 `RediDomainCert::statusLabels()` | 硬编码 | 无 | 无 | 列表展示 |
| `last_error`、健康检查 `RediHealthReport` | 写入/展示中文 | 无 | 无 | **库内历史数据不会随 UI 语言变** |
| 中间件 `RejectDefaultAdminPassword` | Toast 中文 | 无 | 无 | |
| CSV 导出表头 | **英文列名** | 已是英文 | — | 与 UI 语言不一致，属产品决策 |
| 节点 API | 英文 message | 已是 | — | 可保持英文 |

**双语全覆盖判定：否（覆盖率粗估：框架壳 ~30%，自研业务 ~0%）。**

### 2.5 菜单数据来源

绿场 SQL `admin_menu` 示例：

- 业务项：`站点配置`、`分组管理`、`域名管理`、`系统外观`、`DNS与节点池`、`节点管理` → **中文 title 存库**。
- 内置 `Admin` 下：`Users`、`Roles` 等 → **英文 title 存库**。

因此即使打开 Dcat 英文包，**侧栏仍会中英混杂**，除非改菜单渲染或改库内 title 策略。

### 2.6 「系统外观」与多语言

**阶段 16 已实现**：`admin_settings.platform_branding` JSON 含顶层兼容字段与 `locales.{zh_CN|zh_TW|en}`；解析顺序为当前界面语言 → `zh_CN` → 顶层旧字段 → `redi-branding` 默认。Logo / Favicon 仍全平台共用。

---

## 3. 简 / 繁 / 英三语是否可行

**可行。** 理由：

1. Laravel 原生支持多 `locale` 目录；项目已有 `zh_CN`、`zh_TW` 框架包。
2. Dcat Admin 2.x 支持配置多语言列表 + 顶栏语言切换（需在 `config/admin.php` 启用 `languages` 并挂接 `SetLocale` 类中间件，以官方文档为准）。
3. 繁体可与简体**分文件维护**（`zh_TW/redi.php`），避免运行时简繁转换带来的术语错误；少量通用文案可用开源转换作**初稿**，再人工校对 DNS/证书术语。

不建议仅依赖「简→繁自动转换」作为终态。

---

## 4. 推荐技术方案

### 4.1 语言标识与优先级

统一使用：

- `zh_CN` — 简体中文（默认）
- `zh_TW` — 繁体中文
- `en` — 英文

修复 `config/app.php` 中 `locale` 为 `zh_CN`。

**解析优先级**（建议）：

1. 已登录管理员：`admin_users` 增加 `locale` 字段（或 `admin_settings` per-user）— **最符合「每人选语言」**。
2. 否则：Session `locale`（Dcat 切换写入）。
3. 否则：`Accept-Language`（仅首次访问）。
4. 否则：`config('app.locale')`。

实现 `App\Http\Middleware\SetAdminLocale` 于 `web` + `admin` 中间件栈，在 `Admin::booting` 之前执行。

### 4.2 文案组织

新增业务命名空间文件（示例）：

```
resources/lang/zh_CN/redi.php   # 主业务
resources/lang/zh_TW/redi.php
resources/lang/en/redi.php
```

建议键结构（示例）：

```php
// redi.php
'menu' => [
    'site' => '站点配置',
    'groups' => '分组管理',
    ...
],
'domain' => [
    'title' => '域名管理',
    'columns' => [
        'original_url' => '原始域名',
        ...
    ],
    'cert_status' => [
        'none' => '未申请',
        ...
    ],
],
'errors' => [
    'acme' => [...],
    'dns' => [...],
],
```

代码侧：

```php
__('redi.domain.title')
// 或 trans('redi.domain.columns.original_url')
```

**错误类文案**：对外展示用 `__('redi.errors....')`；`last_error` 建议逐步改为 **错误码 + 参数**（如 `cert.deploy.no_enabled_nodes`），展示时再 `trans`；历史纯中文记录可原样显示或仅对新记录生效。

### 4.3 菜单国际化

任选其一（推荐 1）：

1. **URI → 翻译键映射**（不改库）：`App\Admin\Bootstrap\MenuTranslator` 在菜单渲染前把 `domain`、`group`、`platform-dns` 等 URI 的 title 替换为 `__('redi.menu.xxx')`。
2. **迁移改 title 为键名**：如 `redi.menu.domain`，渲染时 `__(title)`（需确认 Dcat 是否原样输出）。
3. **代码注册菜单**替代 SQL 种子（改动面大，长期最干净）。

绿场 SQL 与增量 migration 需同步，避免新装仍是中文 title。

### 4.4 Dcat 表单 / Grid 改造模式

| 原写法 | 改法 |
|--------|------|
| `$grid->column('x', '原始域名')` | `$grid->column('x', __('redi.domain.columns.original_url'))` |
| `$this->text('edge_zone', '平台主域名')` | 第二参数改为 `__()` |
| `admin_toastr('请先改密', ...)` | `__('redi.auth.default_password')` |
| `return '域名管理';` in `title()` | `return __('redi.domain.title');` |

Grid 内 `display` 闭包里的「已指向」「未检测到」等同样抽取。

### 4.5 校验与 Model 消息

Laravel 自定义属性名与规则消息可放在 `redi.php` 的 `validation` 段，或 `resources/lang/{locale}/validation.php` 的 `attributes` / `custom`。

`RediAdminDomain`、`RediNode` 等 Model 中 `=> '中文'` 改为 `=> __('redi....')`（注意：**校验运行在请求周期内**，可随 locale 变化）。

### 4.6 Services 层分层策略

| 类型 | 策略 |
|------|------|
| 抛给后台 UI 的 `RuntimeException` / `last_error` | 改为翻译键或 `trans()` 后的字符串（写入 DB 时记录 locale 或错误码） |
| `redi:health` / Webhook 中文句子 | 运维向：可保持中文；若需英文监控，增加 `en` 模板或 `--locale=en` |
| 日志 `Log::` | 可保持中文或英文单一语言，与 UI 无关 |

### 4.7 测试与质量门禁

1. **键完整性脚本**：`php artisan redi:lang-check`（自建）扫描 `__('redi.` 所需键在三语言文件中是否存在。
2. **CI**：lang-check 失败则阻断合并。
3. **人工走查清单**：按 `docs/CONSOLE.md` 菜单逐项切换三语截图对比。
4. **可选**：Pest 测试固定 `App::setLocale('en')` 后访问某 Controller 断言响应含关键英文字符串。

### 4.8 文档

仓库 `docs/*.md` 与 `CONSOLE.md` 为中文运维文档；**不阻塞后台三语**。若需英文文档，建议单独 `docs/en/` 目录，与代码 i18n 分期。

---

## 5. 实施阶段建议

### 阶段 0 — 决策（0.5～1 人日）

- 确认三语范围：仅后台 UI vs 含 Artisan/Webhook。
- 确认品牌文案策略（§2.6 A 或 B）。
- 确认 `last_error` 是否引入错误码（强烈建议，避免库内双语混乱）。

### 阶段 1 — 基础设施（2～3 人日）

- 修正 `locale`；新增 `SetAdminLocale`；Dcat `languages` + 顶栏切换。
- 用户表 `locale` 字段 + 「用户设置」页下拉保存。
- 新增 `redi.php` 三语骨架；`en` 补全 `global.php` / `menu.php`（可与 Dcat 对齐）。
- 菜单 URI 映射翻译（§4.3）。

### 阶段 2 — 后台 UI 抽取（8～15 人日，占主要工作量）

按模块 PR，建议顺序：

1. `HomeController`、中间件 Toast  
2. `RediAdminDomainController` + 相关 Actions/Forms（最大）  
3. `RediAdminGroupController`  
4. `RediNodeController` + 节点 Actions  
5. `PlatformDnsController` / `PlatformSettingsController` + Forms  
6. `OpsController`、Auth 扩展  

每模块：替换硬编码 → 补三语键 → 走查。

### 阶段 3 — 领域服务与状态（5～8 人日）

- `RediDomainCert::statusLabels()`、`CertificateDeployService`、`IssueDomainCertificateJob`、`RediHealthReport` 等。
- 引入 `RediErrorCode` 枚举 + `redi.errors.{code}` 翻译表（可选但推荐）。

### 阶段 4 — 数据与种子（1～2 人日）

- `db/redi_admin.sql`、相关 migration 菜单 title 策略与文档同步。
- 升级说明：已有环境菜单仍中文 title 时，提供 `php artisan redi:sync-menu-i18n` 或文档说明手动改菜单。

### 阶段 5 — 验收与回归（2～3 人日）

- lang-check CI、三语截图、证书/DNS 关键路径 E2E。
- 英文环境下跑一轮：建域名 → 预检 → 申请证书（staging）。

**粗估总工作量：约 18～30 人日**（取决于是否做错误码、是否翻译 Webhook/CLI、审查粒度）。两人并行可缩短日历时间，但需统一键名规范。

---

## 6. 风险与注意事项

1. **库内历史 `last_error`**：切换 UI 语言不会翻译旧数据；需产品接受或做错误码迁移。
2. **菜单存库**：与语言包双源真相，必须通过映射或迁移统一。
3. **混杂语言**：当前 Admin 子菜单英文、业务菜单中文；未完成前切换 locale 体验更差，宜**阶段 1 后即改菜单**。
4. **CSV 英文表头**：若 UI 为中文，可考虑表头也 `trans` 或提供导出语言参数。
5. **第三方**：Dcat / TinyMCE 等插件语言随 Dcat 配置；自定义 JS（如 `redi-copy-cname`）若有提示文案需扫一遍。
6. **繁体质量**：证书、DNS、ACME 术语需台湾/香港惯用译法校对，勿机翻直上生产。

---

## 7. 验收标准（建议写入 Issue/PR）

- [ ] 超级管理员与普通租户账号均可在顶栏切换 **zh_CN / zh_TW / en**，刷新后保持（已登录读用户 `locale`）。
- [ ] `docs/CONSOLE.md` 所列菜单页面在 **en** 下无大块中文（允许品牌自定义名称、用户填写的域名/URL）。
- [ ] 域名列表、证书状态、DNS 表单、节点管理、批量导入域名 **help 文本** 均有三语键。
- [ ] `php artisan redi:lang-check`（或同等脚本）通过。
- [x] 新增文案禁止 `app/Admin` / `app/Models` 字符串字面量含汉字（`scripts/verify-admin-i18n-invariants.sh`）。

---

## 8. 结论（2026-10-25 更新）

| 问题 | 答案 |
|------|------|
| 后台可见界面是否三语？ | **是（阶段 16 止）**。`redi-*` + Dcat 语言包；系统外观 per-locale；`RediLangParityTest` + `verify-admin-i18n-invariants.sh` 防回退。 |
| 部署注意 | 执行 `php artisan migrate`（`admin_users.locale`）；顶栏或登录页切换语言；系统外观按语言 Tab 填写品牌名。 |
| 仍未三语 | Service/CLI/节点 API、`last_error` 正文、库内历史数据。 |
| 后续可选 | 错误码 + `redi.errors.*`；菜单 DB title 改为 URI 键（绿场 SQL）；CSV **数据列**仍用英文枚举（便于脚本解析）。 |

---

## 附录 A — 主要待抽取文件清单（非 exhaustive）

- `app/Admin/Controllers/*.php`
- `app/Admin/Forms/*.php`
- `app/Admin/Actions/**/*.php`
- `app/Services/CertificateDeployService.php`、`RediHealthReport.php`、`EdgeDnsSyncService.php`、`DomainBatchCreator.php`、DNS `*Provider.php`
- `app/Models/RediDomainCert.php`、`RediAdminDomain.php`、`RediNode.php`、`RediAdminGroup.php`
- `app/Jobs/IssueDomainCertificateJob.php`
- `app/Http/Middleware/RejectDefaultAdminPassword.php`
- `app/Support/PlatformBranding.php`（默认值）

## 附录 B — 配置修正示例

```php
// config/app.php
'locale' => 'zh_CN',
'fallback_locale' => 'en', // 可选：缺键时回退英文而非中文，便于发现漏翻；产品可改为 zh_CN
```

是否将 `fallback_locale` 设为 `en` 由团队决定：**利于漏键暴露** vs **中文环境缺键仍显示中文**。
