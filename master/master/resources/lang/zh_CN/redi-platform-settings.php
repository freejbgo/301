<?php

return [
    'page' => [
        'title'       => '系统外观',
        'description' => '按界面语言分别设置名称与标题；Logo / Favicon 全平台共用',
    ],
    'tabs' => [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁体中文',
        'en'    => 'English',
    ],
    'sections' => [
        'shared_assets' => '共用图片（各语言界面相同）',
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
        'system_name' => '当前语言界面下，侧边栏 Logo 旁显示的名称',
        'page_title'  => '当前语言界面下的浏览器标签页标题，留空则与系统名称相同',
        'login_title' => '当前语言界面下的登录页主标题，留空则与系统名称相同',
        'logo'        => 'PNG、JPG、GIF 或 WEBP。未上传时侧栏只显示系统名称',
        'logo_mini'   => '留空则使用上方 Logo。未上传任何 Logo 时，收起侧栏不显示图片',
        'favicon_shared' => '各语言界面共用同一图标。PNG、JPG、GIF、WEBP 或 ICO。未上传时浏览器不显示站点图标',
    ],
    'messages' => [
        'saved'     => '系统外观已保存',
        'forbidden' => '仅超级管理员可修改系统外观',
    ],
];
