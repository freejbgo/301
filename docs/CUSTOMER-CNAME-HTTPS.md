# 客户接入说明：CNAME 与 HTTPS（无 TXT）

## 您需要做的（仅 DNS）

1. 在跳转服务商后台添加您的**主域名**（如 `example.com`）。
2. 复制后台给出的 **CNAME 目标**（形如 `abcd1234.平台域名.com`）。
3. 在域名注册商/DNS 面板添加记录：
   - 类型：**CNAME**
   - 主机记录：`@` 或根域（按厂商界面）
   - 记录值：上述 CNAME 目标

**不需要**添加 TXT、不需要把 NS 交给平台。

## 根域不能 CNAME 时

部分注册商不允许根域 `@` 做 CNAME，可使用：

- **ALIAS / ANAME / 扁平 CNAME**（云解析常见）
- 或仅将 **www** CNAME 到平台目标，并在业务上引导用户访问 `www`

平台一期**不提供**「逐个域名加 TXT 验证书」流程；证书在 CNAME 生效后由服务商后台 **一键申请（HTTP-01）**。

## www 与多域名

- `example.com` 与 `www.example.com` 在后台需**各添加一行**，各申请一张证书（或自行把 `www` CNAME 到 `@` 后只维护一条，视解析商能力而定）。

## HTTPS

CNAME 生效且证书申请成功后，访问将自动支持 **HTTPS** 跳转；若开启「强制 HTTPS」，HTTP 会 301 到 HTTPS。
