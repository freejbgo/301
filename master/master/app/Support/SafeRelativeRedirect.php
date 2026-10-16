<?php

namespace App\Support;

class SafeRelativeRedirect
{
    /** 仅允许站内相对路径（拒绝 // 协议相对外链）。 */
    public static function isSafe(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//');
    }
}
