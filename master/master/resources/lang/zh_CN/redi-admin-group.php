<?php

return [
    'labels' => [
        'title'           => '分组管理',
        'batch_redirect'  => '批量修改跳转',
        'batch_modal'     => '批量修改跳转',
        'no_tenant'       => '请先创建租户管理员账号（非 administrator 角色），再建分组。',
    ],
    'fields' => [
        'name'            => '名称',
        'status'          => '状态',
        'admin_name'      => '所属账号',
        'owner_admin_id'  => '所属租户账号',
    ],
    'options' => [
        'status' => [1 => '启用', 2 => '停用'],
    ],
    'help' => [
        'owner_admin_id' => '超级管理员建分组时必须指定租户，避免分组挂在超管账号下。',
    ],
    'validation' => [
        'delete_has_domains' => '该分组下还有 :count 条域名，请先迁移或删除域名后再删分组。',
        'owner_invalid'      => '请选择有效的租户账号',
        'owner_not_tenant'   => '不能将分组挂在超级管理员账号下',
    ],
];
