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
        'name_required'      => '分组名称不能为空',
        'delete_has_domains' => '这个分组里还有 :count 个域名，所以不能删除。请先打开域名列表，把这些域名改到别的分组，或者先删除这些域名，然后再删除这个分组。',
        'owner_invalid'      => '请选择有效的租户账号',
        'owner_not_tenant'   => '不能将分组挂在超级管理员账号下',
    ],
    'actions' => [
        'batch_enable' => [
            'title' => '批量启用',
            'error_none' => '没有可启用的分组',
            'success' => '已启用 :count 个分组',
        ],
        'batch_disable' => [
            'title' => '批量停用',
            'confirm' => '停用后新建域名时无法选择这些分组。继续？',
            'error_none' => '没有可停用的分组',
            'success' => '已停用 :count 个分组',
        ],
        'batch_delete' => [
            'title' => '批量删除',
            'confirm' => '分组里还有域名时不能删除。需要先把域名改到别的分组，或先删除这些域名。继续删除空分组？',
            'error_none' => '没有可删除的分组',
            'success' => '已删除 :count 个分组',
        ],
        'batch_common' => [
            'skipped' => '（跳过 :count 个无需变更）',
            'partial_errors' => '另有 :count 条未成功',
        ],
    ],
    'batch_mutator' => [
        'errors' => [
            'status_invalid' => '状态无效',
            'no_groups' => '没有可操作的分组',
            'row_save' => ':label：:reason',
            'row_delete' => ':label：:reason',
            'save_failed' => '保存失败',
        ],
    ],
    'url_set' => [
        'confirm_title'   => '您确定要提交表单吗',
        'confirm_content' => '将更新该分组内所有域名的跳转链接',
        'field_skip_url'  => '跳转链接',
        'success'         => '已更新分组内域名的跳转链接',
        'success_all_skipped' => '组内 :count 个域名跳转链接已是目标值，无需变更',
        'error_group_id'  => '分组 id 异常',
        'error_skip_url'  => '跳转链接异常',
        'error_group'     => '分组无效',
        'error_forbidden' => '分组无效或不属于当前账号',
    ],
];
