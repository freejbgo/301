# 客户接入说明：CNAME 与 HTTPS（无 TXT）

## 您需要做的（仅 DNS）

1. 在跳转服务商后台添加您的**主域名**（如 `example.com`）。
2. 复制后台给出的 **CNAME 目标**（形如 `abcd1234.平台域名.com`）。
3. 在域名注册商/DNS 面板添加记录：
   - 类型：**CNAME**
   - 主机记录：`@` 或根域（按厂商界面）
   - 记录值：上述 CNAME 目标

**不需要**添加 TXT，不需要把 NS 交给平台，也不需要把域名 A 记录指到某一台服务器 IP。

## 根域不能 CNAME 时

部分注册商不允许根域 `@` 做 CNAME，可使用：

- **ALIAS / ANAME / 扁平 CNAME**（云解析常见）
- 或仅将 **www** CNAME 到平台目标，并在业务上引导用户访问 `www`

平台**不提供**「逐个域名加 TXT 验证书」主流程；证书在 CNAME 生效后由服务商后台 **一键申请（HTTP-01）**。

## www 与多域名

- `example.com` 与 `www.example.com` 在后台需**各添加一行**，各申请一张证书（或自行把 `www` CNAME 到 `@` 后只维护一条，视解析商能力而定）。

## HTTPS

CNAME 生效且证书申请成功后，访问将自动支持 **HTTPS** 跳转；若开启「强制 HTTPS」，HTTP 会 301 到 HTTPS。

## CNAME 一直不生效 / 后台显示未指向？

1. 确认记录类型为 **CNAME**，记录值与后台给出的目标 **完全一致**（不要多写 `http://` 或路径）。
2. 等待 DNS 传播（常见几分钟到数小时，视注册商而定）。
3. **请联系为您开通跳转服务的运营人员**（平台方）。对方需先在系统里配好节点与 Pool，并把 `{token}.平台域名` 正确发布到公网 DNS；若对方使用「API 自动同步」，同步失败时需在其后台按运维手册处理——**终端客户无需自行改 Pool 记录**。

## 附：平台运营人员（内部，勿整段发给终端客户）

| 情况 | 处理 |
|------|------|
| 客户 CNAME 已填对仍「未指向」 | 检查 Pool A / 租户 token CNAME 是否已在厂商 DNS 生效；[CONSOLE.md](CONSOLE.md) §2–§3 |
| API 同步失败或 `dns_synced_at` 未更新 | [CONSOLE.md](CONSOLE.md) §2 **「同步失败后的检查」**；`redi:health --json` 的 `dns_api_tenants_unsynced` |
| 上线与证书验收 | [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md) F1–F8、[ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) |
| 发给客户的说明 | 仅转发本文 **「您需要做的」** 至 **「HTTPS」** 各节即可 |
