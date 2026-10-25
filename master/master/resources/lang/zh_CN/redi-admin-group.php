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
    'url_set' => [
        'confirm_title'   => '您确定要提交表单吗',
        'confirm_content' => '将更新该分组内所有域名的跳转链接',
        'field_skip_url'  => '跳转链接',
        'success'         => '已更新分组内域名的跳转链接',
        'error_group_id'  => '分组 id 异常',
        'error_skip_url'  => '跳转链接异常',
        'error_group'     => '分组无效',
        'error_forbidden' => '分组无效或不属于当前账号',
    ],
];
