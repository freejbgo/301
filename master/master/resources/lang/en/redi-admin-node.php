<?php

return [
    'labels' => [
        'title'           => 'Nodes',
        'heartbeat_never' => 'Never',
        'online'          => 'Online',
        'offline'         => 'Offline',
        'deploy_ok'       => 'OK',
        'deploy_pending'  => 'Pending :count',
        'deploy_failed'   => 'Failed :count',
        'requeue_confirm' => 'Requeue all failed certificate deployments as pending?',
        'requeue_btn'     => 'Retry all failed deployments',
    ],
    'fields' => [
        'name'              => 'Name',
        'public_ip'         => 'Public IPv4',
        'public_ipv6s'      => 'Public IPv6',
        'enabled'           => 'Enabled',
        'last_heartbeat_at' => 'Last heartbeat',
        'cert_deploy'       => 'Cert deploy',
        'agent_version'     => 'Agent version',
        'updated_at'        => 'Updated',
    ],
    'actions' => [
        'requeue_failed' => [
            'title' => 'Retry failed certs',
            'confirm' => 'Mark failed deployments on this node as pending for re-pull. Continue?',
            'error_admin' => 'Platform administrators only',
            'error_not_found' => 'Node not found',
            'warning_empty' => 'No failed certificate deployments on this node',
            'success' => 'Requeued :count failed deployment(s)',
        ],
        'rotate_token' => [
            'title' => 'Rotate token',
            'confirm' => 'The old token stops working immediately. Continue?',
            'error_admin' => 'Platform administrators only',
            'error_not_found' => 'Node not found',
            'success' => 'New token generated (full value shown once; set REDI_NODE_TOKEN on the node): :masked',
        ],
        'batch_enable' => [
            'title' => 'Batch enable',
            'error_none' => 'No nodes to enable',
            'success' => 'Enabled :count node(s)',
        ],
        'batch_disable' => [
            'title' => 'Batch disable',
            'confirm' => 'Disabling cancels in-flight cert deploy work on those nodes. Continue?',
            'error_none' => 'No nodes to disable',
            'success' => 'Disabled :count node(s)',
        ],
        'batch_requeue_failed' => [
            'title' => 'Batch retry failed certs',
            'confirm' => 'Mark failed deployments on selected nodes as pending. Continue?',
            'error_none' => 'No failed deployments to requeue on selected nodes',
            'success' => 'Requeued :count failed deployment(s)',
        ],
        'batch_rotate_token' => [
            'title' => 'Batch rotate tokens',
            'confirm' => 'Up to :max nodes; old tokens stop immediately. Plaintext tokens appear in the success message. Continue?',
            'error_none' => 'No nodes to process',
            'success' => 'New tokens for :count node(s) (set REDI_NODE_TOKEN on each node):',
        ],
        'batch_common' => [
            'skipped' => ' (skipped :count unchanged)',
            'skipped_nodes' => ' (:count node(s) had nothing to requeue)',
        ],
    ],
    'batch_mutator' => [
        'errors' => [
            'no_nodes' => 'No nodes to process',
            'token_cap' => 'Batch token rotate is limited to :max nodes',
            'requeue_empty' => ':label: no failed deployments',
        ],
    ],
    'form' => [
        'fields' => [
            'public_ip'   => 'Primary public IPv4',
            'public_ipv6' => 'Primary public IPv6',
            'extra_ipv6'  => 'Additional public IPv6',
            'extra_ips'   => 'Additional public IPv4',
            'note'        => 'Notes',
        ],
        'help' => [
            'public_ip'   => 'Main routable IP for this machine (one node per machine).',
            'public_ipv6' => 'Optional. Pool AAAA records are off by default until REDI_PUBLISH_POOL_AAAA is enabled and DNS is synced.',
            'extra_ipv6'  => 'Extra public IPv6 addresses for the pool, one per line. Primary IPv6 required first.',
            'extra_ips'   => 'Extra public IPv4 for the pool, one per line. Still one agent process and one token—do not create extra nodes for these IPs.',
        ],
        'validation' => [
            'public_ip_ipv4'          => 'Primary public IP must be IPv4',
            'extra_ipv6_primary'      => 'Enter primary public IPv6 first',
            'extra_ipv4_invalid'      => 'Enter one IPv4 per line. Invalid: :value',
            'public_ipv6_global'      => 'Primary public IPv6 must be a globally routable unicast address',
            'extra_ipv6_invalid'      => 'Enter one IPv6 per line. Invalid: :value',
            'ipv6_in_use'             => 'IPv6 already used by another node: :value',
            'ipv4_in_use'             => 'IP already used by another node: :value',
        ],
        'created'        => 'Node created',
        'created_toastr' => 'Node created. Full token shown once—set REDI_NODE_TOKEN on the node now: :masked',
    ],
];
