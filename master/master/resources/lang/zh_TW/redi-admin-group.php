<?php

return [
    'labels' => [
        'title'           => '分組管理',
        'batch_redirect'  => '批量修改跳轉',
        'batch_modal'     => '批量修改跳轉',
        'no_tenant'       => '請先創建租戶管理員帳號（非 administrator 角色），再建分組。',
    ],
    'fields' => [
        'name'            => '名稱',
        'status'          => '狀態',
        'admin_name'      => '所屬帳號',
        'owner_admin_id'  => '所屬租戶帳號',
    ],
    'options' => [
        'status' => [1 => '啟用', 2 => '停用'],
    ],
    'help' => [
        'owner_admin_id' => '超級管理員建分組時必須指定租戶，避免分組掛在超管帳號下。',
    ],
    'validation' => [
        'delete_has_domains' => '該分組下還有 :count 條域名，請先遷移或刪除域名後再刪分組。',
        'owner_invalid'      => '請選擇有效的租戶帳號',
        'owner_not_tenant'   => '不能將分組掛在超級管理員帳號下',
    ],
];
