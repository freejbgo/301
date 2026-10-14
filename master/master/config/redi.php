<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum redi-multi agent version
    |--------------------------------------------------------------------------
    |
    | Nodes must send a "version" on register and heartbeat that is >= this value.
    | Leave empty in .env to disable enforcement.
    |
    */

    'min_agent_version' => env('REDI_MIN_AGENT_VERSION', ''),

    /*
    |--------------------------------------------------------------------------
    | 禁止继续使用种子/文档中的默认后台密码
    |--------------------------------------------------------------------------
    */

    'block_default_admin_password' => env('REDI_BLOCK_DEFAULT_ADMIN_PASSWORD', true),

    'known_default_admin_passwords' => [
        'Redi301@Install2026',
    ],

];
