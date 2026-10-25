<?php

return [
    'page' => [
        'title'       => '系統外觀',
        'description' => '全平台共用一套品牌設置',
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
        'system_name' => '側邊欄 Logo 旁顯示的名稱（全平台單語；各語言介面顯示相同字串，英文站請直接填英文）',
        'page_title'  => '瀏覽器標籤頁標題，留空則與系統名稱相同',
        'login_title' => '登錄頁主標題，留空則與系統名稱相同',
        'logo'        => '建議 PNG，高度約 35px',
        'logo_mini'   => '留空則使用上方 Logo',
    ],
    'messages' => [
        'saved'     => '系統外觀已保存',
        'forbidden' => '僅超級管理員可修改系統外觀',
    ],
];
