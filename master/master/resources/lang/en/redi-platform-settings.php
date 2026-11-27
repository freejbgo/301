<?php

return [
    'page' => [
        'title'       => 'Branding',
        'description' => 'Set the name and titles for each interface language',
    ],
    'tabs' => [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁體中文',
        'en'    => 'English',
    ],
    'cards' => [
        'branding' => 'Platform appearance',
    ],
    'fields' => [
        'system_name' => 'System name',
        'page_title'  => 'Page title',
        'login_title' => 'Login heading',
    ],
    'help' => [
        'system_name' => 'Shown in the sidebar when the admin UI is in this language',
        'page_title'  => 'Browser tab title for this language; defaults to system name when empty',
        'login_title' => 'Login page heading for this language; defaults to system name when empty',
    ],
    'messages' => [
        'saved'     => 'Branding saved',
        'forbidden' => 'Platform administrators only can change branding',
    ],
];
