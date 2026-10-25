<?php

namespace App\Support;

/**
 * Canonical English operational messages (stored in DB, CLI, webhooks, last_error).
 */
final class RediErrorMessage
{
    public static function get(string $key, array $replace = []): string
    {
        return (string) trans('redi-errors.'.$key, $replace, 'en');
    }
}
