<?php

return [
    'page' => [
        'title'       => '系統外觀',
        'description' => '按介面語言分別設置名稱與標題；Logo / Favicon 全平台共用',
    ],
    'tabs' => [
        'zh_CN' => '簡體中文',
        'zh_TW' => '繁體中文',
        'en'    => 'English',
    ],
    'sections' => [
        'shared_assets' => '共用圖片（各語言介面相同）',
    ],
    'cards' => [
        'branding' => '全平台外觀（Logo / 標題）',
    ],
    'fields' => [
        'system_name' => '系統名稱',
        'page_title'  => '網頁標題',
        'login_title' => '登錄框標題',
        'logo'        => 'Logo（展開側欄）',
        'logo_mini'   => 'Logo（收起側欄）',
        'favicon'     => 'Favicon',
    ],
    'help' => [
        'system_name' => '當前語言介面下，側邊欄 Logo 旁顯示的名稱',
        'page_title'  => '當前語言介面下的瀏覽器標籤頁標題，留空則與系統名稱相同',
        'login_title' => '當前語言介面下的登錄頁主標題，留空則與系統名稱相同',
        'logo'        => '建議 PNG，高度約 35px',
        'logo_mini'   => '留空則使用上方 Logo',
        'favicon_shared' => '各語言介面共用同一圖標',
    ],
    'messages' => [
        'saved'     => '系統外觀已保存',
        'forbidden' => '僅超級管理員可修改系統外觀',
    ],
];
