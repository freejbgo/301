<?php

return [
    'labels' => [
        'title'           => '节点管理',
        'heartbeat_never' => '从未',
        'online'          => '在线',
        'offline'         => '离线',
        'deploy_ok'       => '正常',
        'deploy_pending'  => '待部署 :count',
        'deploy_failed'   => '失败 :count',
        'requeue_confirm' => '将全部 failed 证书部署改回 pending？',
        'requeue_btn'     => '重试全部失败部署',
    ],
    'fields' => [
        'name'              => '名称',
        'public_ip'         => '公网 IP',
        'public_ipv6s'      => '公网 IPv6',
        'enabled'           => '启用',
        'last_heartbeat_at' => '最后心跳',
        'cert_deploy'       => '证书部署',
        'agent_version'     => 'Agent 版本',
        'updated_at'        => '更新时间',
    ],
    'actions' => [
        'requeue_failed' => [
            'title' => '重试失败证书',
            'confirm' => '将该节点上 status=failed 的证书部署重新标为 pending，节点将重新拉取。继续？',
            'error_admin' => '仅超级管理员可操作',
            'error_not_found' => '节点不存在',
            'warning_empty' => '该节点没有失败的证书部署记录',
            'success' => '已重新排队 :count 条失败部署',
        ],
        'rotate_token' => [
            'title' => '重置 Token',
            'confirm' => '重置后旧 Token 立即失效，节点需改用新 Token。继续？',
            'error_admin' => '仅超级管理员可操作',
            'error_not_found' => '节点不存在',
            'success' => '新 Token 已生成（完整串仅本次响应可见，请立即写入节点 REDI_NODE_TOKEN）：:masked',
        ],
    ],
];
