<?php

return [
    'issues' => [
        'node_stale' => '节点 #:id :name (:ip) 超过 15 分钟无心跳',
        'cert_deploy_pending' => '仍有 :count 条证书部署待节点拉取/确认（pending）',
        'cert_deploy_failed' => '有 :count 条证书在节点上安装失败（failed）',
        'cert_expired' => '证书已过期：:host（:date，状态 :status）',
        'cert_expiring' => '证书将在 :days 天内到期：:host（:date，状态 :status）',
        'app_debug_enabled' => 'APP_DEBUG=true：生产环境请关闭调试并执行 php artisan config:cache',
        'acme_email_missing' => '未配置 ACME 联系邮箱（后台 DNS 与节点池），无法签发证书',
        'acme_staging_enabled' => '生产环境仍开启 ACME Staging；正式证书请关闭「ACME 测试环境」',
        'edge_zone_missing' => '未配置平台 edge 主域名（edge_zone），租户 CNAME 与证书流程不完整',
        'dns_driver_invalid' => 'DNS 为 API 自动模式但驱动无效（请在后台 DNS 与节点池选择具体厂商驱动）',
        'dns_api_credentials_missing' => 'DNS 为 API 自动模式但未保存 API 凭证，无法同步 pool / 租户 CNAME',
        'dns_zone_id_missing' => 'Cloudflare 驱动未配置 Zone ID，API 同步将失败',
        'node_pool_empty' => '没有启用的边缘节点，证书无法部署、pool DNS 无 A 记录可同步',
        'node_no_public_ip' => '有 :count 台启用节点但未配置公网 IP（public_ip / extra_ips），pool A 记录与 A 池 CNAME 预检将无法工作',
        'cert_validating_stuck' => ':total 张证书超过 :hours 小时仍为 validating（可能 ACME 任务卡住）：:names',
        'queue_failed_jobs' => '队列有 :count 条失败任务。原因在首页下方列表，点「重试失败队列任务」后这个数字会清零。',
        'queue_backlog' => '队列积压 :pending 条（阈值 :threshold），请确认 redi-queue / queue:work 在运行',
        'queue_stale' => '有 :count 条队列任务超过 :hours 小时未执行，可能 worker 未运行',
        'cert_domain_name_drift' => '证书 #:id domain_name 与域名不一致且未能自动对齐：记录 :recorded，应为 :canonical',
        'cert_validating_stuck_more' => ' …等共 :total 个',
    ],
    'hints' => [
        'queue_cache_file' => 'QUEUE_CONNECTION 非 sync 且 CACHE_DRIVER=file：仅适合单台机器单个 queue:work；多 worker 或多机请改 CACHE_DRIVER=redis 并配置 REDIS_*（IssueDomainCertificateJob 的 WithoutOverlapping 依赖共享缓存锁）',
        'queue_database_single_host' => '当前队列为 database：请确保 systemd redi-queue 持续运行；扩容 worker 前先将缓存改为 redis',
        'agent_version_gate_off' => 'REDI_MIN_AGENT_VERSION 为空：节点 Agent 版本门控已关闭，升级节点后请改回 0.1.3',
        'dns_api_tenants_unsynced' => 'DNS API 模式下有 :count 个租户 edge 尚未记录 dns_synced_at；若曾同步失败，厂商 DNS 可能已部分更新，请按后台报错修正后重试「同步 Pool / 租户 CNAME」',
    ],
];
