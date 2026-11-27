<?php

return [
    'page' => [
        'title'       => '系统外观',
        'description' => '按界面语言分别设置名称与标题',
    ],
    'tabs' => [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁体中文',
        'en'    => 'English',
    ],
    'cards' => [
        'branding' => '全平台外观',
    ],
    'fields' => [
        'system_name' => '系统名称',
        'page_title'  => '网页标题',
        'login_title' => '登录框标题',
    ],
    'help' => [
        'system_name' => '当前语言界面下，侧边栏显示的名称',
        'page_title'  => '当前语言界面下的浏览器标签页标题，留空则与系统名称相同',
        'login_title' => '当前语言界面下的登录页主标题，留空则与系统名称相同',
    ],
    'messages' => [
        'saved'     => '系统外观已保存',
        'forbidden' => '仅超级管理员可修改系统外观',
    ],
];
