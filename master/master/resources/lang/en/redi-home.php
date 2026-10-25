<?php

return [
    'labels' => [
        'header'              => '301 Redirect',
        'health_ok'           => 'redi:health reports no open items.',
        'health_more'         => ':count more item(s); run php artisan redi:health',
        'requeue_confirm'     => 'Requeue all failed certificate deployments as pending?',
        'requeue_btn'         => 'Retry all failed deployments',
        'requeue_hint'        => 'Same as php artisan redi:requeue-failed-deployments',
        'ops_card'            => 'Ops self-check (certificate alerts, last 7 days)',
        'tenant_overview'     => 'My overview',
        'tenant_tip'          => 'Complete CNAME/A DNS first, then enable domains and request certificates under Domains.',
        'tenant_stats_enabled'=> 'Enabled domains: :enabled, disabled: :disabled',
        'tenant_stats_cert_active' => 'Active certificates: :count',
        'tenant_stats_cert_pending' => 'Certificates needing attention: :count',
        'tenant_stats_expiring' => 'Expiring within 14 days: :count',
        'hints_heading'       => 'Configuration hints (informational)',
    ],
    'ops' => [
        'requeue_empty'   => 'No failed deployments to requeue',
        'requeue_success' => 'Requeued :count failed deployment(s)',
    ],
    'cards' => [
        'enabled_nodes'    => 'Enabled nodes',
        'stale_nodes'      => 'Stale heartbeat',
        'pending_deploys'  => 'Pending deploys',
        'failed_deploys'   => 'Failed deploys',
        'queue_pending'    => 'Queue backlog',
        'queue_failed'     => 'failed_jobs',
        'domains_enabled'  => 'Enabled domains',
        'certs_active'     => 'Active certs',
        'certs_attention'  => 'Certs pending',
        'certs_expiring'   => 'Expiring soon',
    ],
];
