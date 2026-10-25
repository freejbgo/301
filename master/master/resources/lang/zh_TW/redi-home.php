<?php

return [
    'labels' => [
        'header'              => '301 跳轉系統',
        'health_ok'           => 'redi:health 當前無待處理項。',
        'health_more'         => '另有 :count 項，請執行 php artisan redi:health',
        'requeue_confirm'     => '將全部 failed 憑證部署改回 pending？',
        'requeue_btn'         => '重試全部失敗部署',
        'requeue_hint'        => '等同 php artisan redi:requeue-failed-deployments',
        'ops_card'            => '運營自檢（最近 7 天憑證預警）',
        'tenant_overview'     => '我的業務概覽',
        'tenant_tip'          => '請先完成 CNAME/A 解析，再在「域名管理」中啟用域名並申請憑證。',
        'tenant_stats_enabled'=> '啟用域名：:enabled，停用：:disabled',
        'tenant_stats_cert_active' => '憑證已生效：:count',
        'tenant_stats_cert_pending' => '憑證待處理（解析/簽發/部署等）：:count',
        'tenant_stats_expiring' => '14 天內到期：:count',
    ],
    'cards' => [
        'enabled_nodes'    => '啟用節點',
        'stale_nodes'      => '心跳異常',
        'pending_deploys'  => '憑證待部署',
        'failed_deploys'   => '憑證部署失敗',
        'queue_pending'    => '隊列積壓',
        'queue_failed'     => 'failed_jobs',
        'domains_enabled'  => '啟用域名',
        'certs_active'     => '憑證已生效',
        'certs_attention'  => '憑證待處理',
        'certs_expiring'   => '即將到期',
    ],
];
