<?php

return [
    'labels' => [
        'header'              => '301 跳转系统',
        'health_ok'           => 'redi:health 当前无待处理项。',
        'health_more'         => '另有 :count 项，请执行 php artisan redi:health',
        'requeue_confirm'     => '将全部 failed 证书部署改回 pending？',
        'requeue_btn'         => '重试全部失败部署',
        'requeue_hint'        => '等同 php artisan redi:requeue-failed-deployments（命令行）',
        'ops_card'            => '运营自检（最近 7 天证书预警）',
        'tenant_overview'     => '我的业务概览',
        'tenant_tip'          => '请先完成 CNAME/A 解析，再在「域名管理」中启用域名并申请证书。',
        'tenant_stats_enabled'=> '启用域名：:enabled，停用：:disabled',
        'tenant_stats_cert_active' => '证书已生效：:count',
        'tenant_stats_cert_pending' => '证书待处理（解析/签发/部署等）：:count',
        'tenant_stats_expiring' => '14 天内到期：:count',
        'hints_heading'       => '配置建议（仅供参考，不影响健康结论）',
    ],
    'ops' => [
        'requeue_empty'   => '没有 failed 部署记录需要重试',
        'requeue_success' => '已重新排队 :count 条 failed 部署',
    ],
    'cards' => [
        'enabled_nodes'    => '启用节点',
        'stale_nodes'      => '心跳异常',
        'pending_deploys'  => '证书待部署',
        'failed_deploys'   => '证书部署失败',
        'queue_pending'    => '队列积压',
        'queue_failed'     => 'failed_jobs',
        'domains_enabled'  => '启用域名',
        'certs_active'     => '证书已生效',
        'certs_attention'  => '证书待处理',
        'certs_expiring'   => '即将到期',
    ],
];
