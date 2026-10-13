<?php

namespace App\Services;

use App\Models\RediAdminDomain;

class DomainOriginalUrlGuard
{
    public static function isTakenGlobally(string $originalUrl, ?int $exceptDomainId = null): bool
    {
        $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($originalUrl);
        if ($originalUrl === '') {
            return false;
        }

        $query = RediAdminDomain::query()->where('original_url', $originalUrl);
        if ($exceptDomainId) {
            $query->where('id', '!=', $exceptDomainId);
        }

        return $query->exists();
    }
}
