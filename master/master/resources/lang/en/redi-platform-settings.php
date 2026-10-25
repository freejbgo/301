<?php

return [
    'page' => [
        'title'       => 'Branding',
        'description' => 'One brand profile for the whole platform',
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
        'system_name' => 'Shown next to the sidebar logo',
        'page_title'  => 'Browser tab title; defaults to system name when empty',
        'login_title' => 'Login page heading; defaults to system name when empty',
        'logo'        => 'PNG recommended, ~35px tall',
        'logo_mini'   => 'Uses the main logo when empty',
    ],
    'messages' => [
        'saved'     => 'Branding saved',
        'forbidden' => 'Platform administrators only can change branding',
    ],
];
