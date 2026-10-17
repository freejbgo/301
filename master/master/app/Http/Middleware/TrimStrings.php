<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // 节点 API：须在服务端检测控制字符，不可被全局 trim 掉。
        'domains',
        'domains.*',
        'version',
        'error',
    ];
}
