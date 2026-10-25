<?php

return [
    'labels' => [
        'title'           => '節點管理',
        'heartbeat_never' => '從未',
        'online'          => '在線',
        'offline'         => '離線',
        'deploy_ok'       => '正常',
        'deploy_pending'  => '待部署 :count',
        'deploy_failed'   => '失敗 :count',
        'requeue_confirm' => '將全部 failed 憑證部署改回 pending？',
        'requeue_btn'     => '重試全部失敗部署',
    ],
    'fields' => [
        'name'              => '名稱',
        'public_ip'         => '公網 IP',
        'public_ipv6s'      => '公網 IPv6',
        'enabled'           => '啟用',
        'last_heartbeat_at' => '最後心跳',
        'cert_deploy'       => '憑證部署',
        'agent_version'     => 'Agent 版本',
        'updated_at'        => '更新時間',
    ],
    'actions' => [
        'requeue_failed' => [
            'title' => '重試失敗憑證',
            'confirm' => '將該節點上 status=failed 的憑證部署重新標為 pending，節點將重新拉取。繼續？',
            'error_admin' => '僅超級管理員可操作',
            'error_not_found' => '節點不存在',
            'warning_empty' => '該節點沒有失敗的憑證部署記錄',
            'success' => '已重新排隊 :count 條失敗部署',
        ],
        'rotate_token' => [
            'title' => '重置 Token',
            'confirm' => '重置後舊 Token 立即失效，節點需改用新 Token。繼續？',
            'error_admin' => '僅超級管理員可操作',
            'error_not_found' => '節點不存在',
            'success' => '新 Token 已生成（完整串僅本次響應可見，請立即寫入節點 REDI_NODE_TOKEN）：:masked',
        ],
    ],
];
