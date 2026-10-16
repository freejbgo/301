<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum redi-multi agent version
    |--------------------------------------------------------------------------
    |
    | Nodes must meet this version on all /api/nodes/* (see RejectOldRediAgent).
    | Set REDI_MIN_AGENT_VERSION= in .env to disable enforcement.
    |
    */

    'min_agent_version' => env('REDI_MIN_AGENT_VERSION', '0.1.3'),

    /*
    |--------------------------------------------------------------------------
    | Allow TRUSTED_PROXIES=* in production (config:cache safe)
    |--------------------------------------------------------------------------
    */

    'allow_trust_all_proxies' => env('REDI_ALLOW_TRUST_ALL_PROXIES', false),

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
