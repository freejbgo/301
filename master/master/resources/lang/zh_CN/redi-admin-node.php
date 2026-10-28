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
        'batch_enable' => [
            'title' => '批量启用',
            'error_none' => '没有可启用的节点',
            'success' => '已启用 :count 个节点',
        ],
        'batch_disable' => [
            'title' => '批量停用',
            'confirm' => '停用后会取消该节点上的证书部署任务。继续？',
            'error_none' => '没有可停用的节点',
            'success' => '已停用 :count 个节点',
        ],
        'batch_requeue_failed' => [
            'title' => '批量重试失败证书',
            'confirm' => '将选中节点上 failed 部署改回 pending。继续？',
            'error_none' => '所选节点均无失败部署可重试',
            'success' => '已重新排队 :count 条失败部署',
        ],
        'batch_rotate_token' => [
            'title' => '批量重置 Token',
            'confirm' => '最多处理 :max 个节点；旧 Token 立即失效，成功消息中会列出明文 Token。继续？',
            'error_none' => '没有可操作的节点',
            'success' => '已为 :count 个节点生成新 Token（请立即写入各节点 REDI_NODE_TOKEN）：',
        ],
        'batch_common' => [
            'skipped' => '（跳过 :count 个无需变更）',
            'skipped_nodes' => '（:count 个节点无失败记录）',
        ],
    ],
    'batch_mutator' => [
        'errors' => [
            'no_nodes' => '没有可操作的节点',
            'token_cap' => '批量重置 Token 单次最多 :max 个节点',
            'requeue_empty' => ':label：无 failed 部署',
        ],
    ],
    'form' => [
        'fields' => [
            'public_ip'   => '主公网 IP',
            'public_ipv6' => '主公网 IPv6',
            'extra_ipv6'  => '其他公网 IPv6',
            'extra_ips'   => '其他公网 IP',
            'note'        => '备注',
        ],
        'help' => [
            'public_ip'   => '这台机器对外的主 IP，也是节点程序所在机器。一台机器只建一条节点。',
            'public_ipv6' => '可选。默认不会在 Pool 发布 AAAA 记录；需开启 REDI_PUBLISH_POOL_AAAA 并同步后才会写入厂商 DNS。',
            'extra_ipv6'  => '同一台机器上还要写入 pool 的公网 IPv6，一行一个。须先填写主公网 IPv6。',
            'extra_ips'   => '同一台机器上还要写入 pool 的公网 IPv4，一行一个。Pool 只写 A 记录。仍然只跑一个节点进程、只用一个 Token。不要为这些 IP 再新建节点。',
        ],
        'validation' => [
            'public_ip_ipv4'          => '主公网 IP 必须是 IPv4',
            'extra_ipv6_primary'      => '请先填写主公网 IPv6',
            'extra_ipv4_invalid'      => '请填写 IPv4，一行一个。无效：:value',
            'public_ipv6_global'      => '主公网 IPv6 必须是可发布的全球单播地址',
            'extra_ipv6_invalid'      => '请填写 IPv6，一行一个。无效：:value',
            'ipv6_in_use'             => 'IPv6 已被其他节点使用：:value',
            'ipv4_in_use'             => 'IP 已被其他节点使用：:value',
        ],
        'created'        => '节点已创建',
        'created_toastr' => '节点已创建。完整 Token 仅本次响应可见（请立即写入 REDI_NODE_TOKEN）：:masked',
    ],
];
