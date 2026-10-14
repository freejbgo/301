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

];
