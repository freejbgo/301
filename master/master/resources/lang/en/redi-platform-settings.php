<?php

return [
    'page' => [
        'title'       => 'Branding',
        'description' => 'Titles per UI language; logo and favicon are shared across languages',
    ],
    'tabs' => [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁體中文',
        'en'    => 'English',
    ],
    'sections' => [
        'shared_assets' => 'Shared images (same on every language)',
    ],
    'cards' => [
        'branding' => 'Platform branding (logo / titles)',
    ],
    'fields' => [
        'system_name' => 'System name',
        'page_title'  => 'Page title',
        'login_title' => 'Login heading',
        'logo'        => 'Logo (expanded sidebar)',
        'logo_mini'   => 'Logo (collapsed sidebar)',
        'favicon'     => 'Favicon',
    ],
    'help' => [
        'system_name' => 'Shown next to the sidebar logo when the admin UI is in this language',
        'page_title'  => 'Browser tab title for this language; defaults to system name when empty',
        'login_title' => 'Login page heading for this language; defaults to system name when empty',
        'logo'        => 'PNG, JPG, GIF, or WEBP. Until one is uploaded, the sidebar shows the system name only',
        'logo_mini'   => 'Uses the main logo when empty. With no logo at all, the collapsed sidebar has no image',
        'favicon_shared' => 'Same favicon for every UI language. PNG, JPG, GIF, WEBP, or ICO. No site icon until one is uploaded',
    ],
    'messages' => [
        'saved'     => 'Branding saved',
        'forbidden' => 'Platform administrators only can change branding',
    ],
];
