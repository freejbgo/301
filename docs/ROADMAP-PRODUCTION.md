# 已知限制与未来工作

**已交付能力、上线检查与验收**见 [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md)、[PLATFORM-ARCHITECTURE.md](PLATFORM-ARCHITECTURE.md)、[ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)。

## 计划中的增强（不阻塞首单）

| 项 | 说明 |
|----|------|
| Laravel / PHP 大版本 | 当前锁 PHP 8.0 与 Dcat `2.2.3-beta` |
| 租户隔离 | 持续收紧 API/后台全表策略与审计 |
| 运行环境 | CentOS 7 已 EOL，长期使用 vault 源；换 OS 属单独项目 |

## 产品约定（排障时勿当缺陷）

- 正常跳转：**200 + JS**；仅 **force HTTPS** 为 TLS **301**。  
- 平台内 `original_url` 唯一；新建域名默认停用，启用前须 DNS 预检。  

细节与后台操作见 [CONSOLE.md](CONSOLE.md) §8。
