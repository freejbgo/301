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
    ],
];
