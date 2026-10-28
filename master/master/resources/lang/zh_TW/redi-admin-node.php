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
        'batch_enable' => [
            'title' => '批量啟用',
            'error_none' => '沒有可啟用的節點',
            'success' => '已啟用 :count 個節點',
        ],
        'batch_disable' => [
            'title' => '批量停用',
            'confirm' => '停用後會取消該節點上的憑證部署任務。繼續？',
            'error_none' => '沒有可停用的節點',
            'success' => '已停用 :count 個節點',
        ],
        'batch_requeue_failed' => [
            'title' => '批量重試失敗憑證',
            'confirm' => '將選中節點上 failed 部署改回 pending。繼續？',
            'error_none' => '所選節點均無失敗部署可重試',
            'success' => '已重新排隊 :count 條失敗部署',
        ],
        'batch_rotate_token' => [
            'title' => '批量重置 Token',
            'confirm' => '最多處理 :max 個節點；舊 Token 立即失效，成功訊息中會列出明文 Token。繼續？',
            'error_none' => '沒有可操作的節點',
            'success' => '已為 :count 個節點生成新 Token（請立即寫入各節點 REDI_NODE_TOKEN）：',
        ],
        'batch_common' => [
            'skipped' => '（跳過 :count 個無需變更）',
            'skipped_nodes' => '（:count 個節點無失敗記錄）',
        ],
    ],
    'batch_mutator' => [
        'errors' => [
            'no_nodes' => '沒有可操作的節點',
            'token_cap' => '批量重置 Token 單次最多 :max 個節點',
            'requeue_empty' => ':label：無 failed 部署',
        ],
    ],
    'form' => [
        'fields' => [
            'public_ip'   => '主公網 IP',
            'public_ipv6' => '主公網 IPv6',
            'extra_ipv6'  => '其他公網 IPv6',
            'extra_ips'   => '其他公網 IP',
            'note'        => '備註',
        ],
        'help' => [
            'public_ip'   => '這台機器對外的主 IP，也是節點程序所在機器。一台機器只建一條節點。',
            'public_ipv6' => '可選。默認不會在 Pool 發布 AAAA 記錄；需開啟 REDI_PUBLISH_POOL_AAAA 並同步後才會寫入廠商 DNS。',
            'extra_ipv6'  => '同一台機器上還要寫入 pool 的公網 IPv6，一行一個。須先填寫主公網 IPv6。',
            'extra_ips'   => '同一台機器上還要寫入 pool 的公網 IPv4，一行一個。Pool 只寫 A 記錄。仍然只跑一個節點進程、只用一個 Token。不要為這些 IP 再新建節點。',
        ],
        'validation' => [
            'public_ip_ipv4'          => '主公網 IP 必須是 IPv4',
            'extra_ipv6_primary'      => '請先填寫主公網 IPv6',
            'extra_ipv4_invalid'      => '請填寫 IPv4，一行一個。無效：:value',
            'public_ipv6_global'      => '主公網 IPv6 必須是可發布的全球單播地址',
            'extra_ipv6_invalid'      => '請填寫 IPv6，一行一個。無效：:value',
            'ipv6_in_use'             => 'IPv6 已被其他節點使用：:value',
            'ipv4_in_use'             => 'IP 已被其他節點使用：:value',
        ],
        'created'        => '節點已創建',
        'created_toastr' => '節點已創建。完整 Token 僅本次響應可見（請立即寫入 REDI_NODE_TOKEN）：:masked',
    ],
];
