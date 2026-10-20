<?php

namespace App\Support;

class SafeRelativeRedirect
{
    /** 仅允许站内相对路径（拒绝 // 协议相对外链）。 */
    public static function isSafe(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && ! str_contains($path, "\r")
            && ! str_contains($path, "\n")
            && ! str_contains($path, "\0")
            && ! str_contains($path, "\t")
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\');
    }
}
