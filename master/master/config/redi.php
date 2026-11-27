<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum redi-multi agent version
    |--------------------------------------------------------------------------
    |
    | Nodes must meet this version on all /api/nodes/* (see RejectOldRediAgent).
    | Set REDI_MIN_AGENT_VERSION= in .env to disable enforcement.
    |
    */

    'min_agent_version' => env('REDI_MIN_AGENT_VERSION', '0.1.3'),

    /*
    |--------------------------------------------------------------------------
    | Allow TRUSTED_PROXIES=* in production (config:cache safe)
    |--------------------------------------------------------------------------
    */

    'allow_trust_all_proxies' => env('REDI_ALLOW_TRUST_ALL_PROXIES', false),

    /*
    |--------------------------------------------------------------------------
    | 禁止继续使用种子/文档中的默认后台密码
    |--------------------------------------------------------------------------
    */

    'block_default_admin_password' => env('REDI_BLOCK_DEFAULT_ADMIN_PASSWORD', true),

    'known_default_admin_passwords' => [
        'Redi301@Install2026',
    ],

    /*
    |--------------------------------------------------------------------------
    | 后台界面语言（顶栏切换）
    |--------------------------------------------------------------------------
    */

    'admin_locales' => ['zh_CN', 'zh_TW', 'en'],

    /*
    |--------------------------------------------------------------------------
    | 启用域名前须解析指向平台（CNAME 到租户 edge 或 A 到启用节点公网 IP）
    |--------------------------------------------------------------------------
    */

    'require_dns_pointing_to_enable' => env('REDI_REQUIRE_DNS_POINTING_TO_ENABLE', true),

    'dns_precheck_allow_a_to_pool' => env('REDI_DNS_PRECHECK_ALLOW_A_TO_POOL', true),

    'dns_precheck_allow_aaaa_to_pool' => env('REDI_DNS_PRECHECK_ALLOW_AAAA_TO_POOL', false),

    /*
    |--------------------------------------------------------------------------
    | 旧的环境变量兜底。后台「DNS 与节点池」的「发布 Pool IPv6」写在库里，有这一列时不再读这里。
    |--------------------------------------------------------------------------
    */

    'publish_pool_aaaa' => env('REDI_PUBLISH_POOL_AAAA', false),

    /*
    |--------------------------------------------------------------------------
    | DNSPod China API `record_line` for pool sync (vendor default line name)
    |--------------------------------------------------------------------------
    */

    'dnspod_cn_record_line' => env('REDI_DNSPOD_CN_RECORD_LINE', '默认'),

    /*
    |--------------------------------------------------------------------------
    | redi:health --notify Webhook（JSON POST，含 text 纯文摘要）
    |--------------------------------------------------------------------------
    */

    'health_webhook_url' => env('REDI_HEALTH_WEBHOOK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | 运维 Webhook（cron 失败等）；未配置时回退到 REDI_HEALTH_WEBHOOK_URL
    |--------------------------------------------------------------------------
    */

    'ops_webhook_url' => env('REDI_OPS_WEBHOOK_URL', ''),

    'health_queue_pending_threshold' => min(100_000, max(1, (int) env('REDI_HEALTH_QUEUE_PENDING_THRESHOLD', 80))),

    'health_queue_stale_hours' => min(720, max(1, (int) env('REDI_HEALTH_QUEUE_STALE_HOURS', 2))),

    /*
    |--------------------------------------------------------------------------
    | 证书长期处于 validating 视为卡住（redi:health / 续期重试）
    |--------------------------------------------------------------------------
    */

    'health_validating_stuck_hours' => min(720, max(1, (int) env('REDI_HEALTH_VALIDATING_STUCK_HOURS', 2))),

    'renew_validating_stuck_hours' => min(720, max(1, (int) env('REDI_RENEW_VALIDATING_STUCK_HOURS', 3))),

    /*
    |--------------------------------------------------------------------------
    | ACME HTTP-01 公网探测轮询间隔（秒，最小 1）
    |--------------------------------------------------------------------------
    */

    'acme_http01_poll_seconds' => min(60, max(1, (int) env('REDI_ACME_HTTP01_POLL_SECONDS', 3))),

];
