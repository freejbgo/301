<?php

return [
    'issues' => [
        'node_stale' => 'Node #:id :name (:ip) — no heartbeat for 15+ minutes',
        'cert_deploy_pending' => ':count certificate deployment(s) still pending node pull/ack',
        'cert_deploy_failed' => ':count certificate deployment(s) failed on nodes',
        'cert_expired' => 'Certificate expired: :host (:date, status :status)',
        'cert_expiring' => 'Certificate expires within :days day(s): :host (:date, status :status)',
        'app_debug_enabled' => 'APP_DEBUG=true in production — disable debug and run php artisan config:cache',
        'acme_email_missing' => 'ACME contact email not set (DNS & node pool) — cannot issue certificates',
        'acme_staging_enabled' => 'ACME staging still enabled in production — turn off ACME staging for live certs',
        'edge_zone_missing' => 'Platform edge apex (edge_zone) not configured — tenant CNAME / cert flow incomplete',
        'dns_driver_invalid' => 'DNS API mode but driver invalid — pick a provider under DNS & node pool',
        'dns_api_credentials_missing' => 'DNS API mode but API credentials missing — cannot sync pool / tenant CNAMEs',
        'dns_zone_id_missing' => 'Cloudflare driver missing Zone ID — API sync will fail',
        'node_pool_empty' => 'No enabled edge nodes — certs cannot deploy and pool DNS has no A records',
        'node_no_public_ip' => ':count enabled node(s) without public IP (public_ip / extra_ips) — pool A and precheck will not work',
        'cert_validating_stuck' => ':total certificate(s) stuck in validating for :hours+ hour(s) (ACME may be stuck): :names',
        'queue_failed_jobs' => ':count failed queue job(s). The reason is in the list below. Retry failed queue jobs clears this count.',
        'queue_backlog' => 'Queue backlog :pending (threshold :threshold) — ensure redi-queue / queue:work is running',
        'queue_stale' => ':count job(s) waiting over :hours hour(s) — worker may be down',
        'cert_domain_name_drift' => 'Cert #:id domain_name mismatch (could not auto-align): recorded :recorded, expected :canonical',
        'cert_validating_stuck_more' => ' …and :total more',
    ],
    'hints' => [
        'queue_cache_file' => 'QUEUE_CONNECTION is not sync and CACHE_DRIVER=file — OK for one queue:work on one host; use redis cache and REDIS_* for multiple workers or hosts (IssueDomainCertificateJob WithoutOverlapping needs a shared lock).',
        'queue_database_single_host' => 'Queue driver is database — keep systemd redi-queue running; switch cache to redis before scaling workers.',
        'agent_version_gate_off' => 'REDI_MIN_AGENT_VERSION is empty — agent version gate is off; set it back to 0.1.3 after upgrading nodes.',
        'dns_api_tenants_unsynced' => 'DNS API mode: :count tenant edge(s) missing dns_synced_at; if sync failed earlier, provider DNS may be partial — fix errors in the admin console and run pool / tenant CNAME sync again.',
    ],
];
