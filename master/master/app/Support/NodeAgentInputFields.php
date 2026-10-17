<?php

namespace App\Support;

/**
 * 节点 Agent JSON 字段：须在服务端检测控制字符，全局中间件不可 trim / 空串转 null。
 */
final class NodeAgentInputFields
{
    /**
     * @var array<int, string>
     */
    public const MIDDLEWARE_EXCEPT = [
        'domains',
        'domains.*',
        'version',
        'error',
    ];
}
