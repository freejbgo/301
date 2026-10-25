<?php

return [
    'page' => [
        'title'       => '系统外观',
        'description' => '全平台共用一套品牌设置',
    ],
    'cards' => [
        'branding' => '全平台外观（Logo / 标题）',
    ],
    'fields' => [
        'system_name' => '系统名称',
        'page_title'  => '网页标题',
        'login_title' => '登录框标题',
        'logo'        => 'Logo（展开侧栏）',
        'logo_mini'   => 'Logo（收起侧栏）',
        'favicon'     => 'Favicon',
    ],
    'help' => [
        'system_name' => '侧边栏 Logo 旁显示的名称',
        'page_title'  => '浏览器标签页标题，留空则与系统名称相同',
        'login_title' => '登录页主标题，留空则与系统名称相同',
        'logo'        => '建议 PNG，高度约 35px',
        'logo_mini'   => '留空则使用上方 Logo',
    ],
    'messages' => [
        'saved'     => '系统外观已保存',
        'forbidden' => '仅超级管理员可修改系统外观',
    ],
];
