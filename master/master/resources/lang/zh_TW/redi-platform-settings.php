<?php

return [
    'page' => [
        'title'       => '系統外觀',
        'description' => '按介面語言分別設置名稱與標題',
    ],
    'tabs' => [
        'zh_CN' => '簡體中文',
        'zh_TW' => '繁體中文',
        'en'    => 'English',
    ],
    'cards' => [
        'branding' => '全平台外觀',
    ],
    'fields' => [
        'system_name' => '系統名稱',
        'page_title'  => '網頁標題',
        'login_title' => '登錄框標題',
    ],
    'help' => [
        'system_name' => '當前語言介面下，側邊欄顯示的名稱',
        'page_title'  => '當前語言介面下的瀏覽器標籤頁標題，留空則與系統名稱相同',
        'login_title' => '當前語言介面下的登錄頁主標題，留空則與系統名稱相同',
    ],
    'messages' => [
        'saved'     => '系統外觀已保存',
        'forbidden' => '僅超級管理員可修改系統外觀',
    ],
];
