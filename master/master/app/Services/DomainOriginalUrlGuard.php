<?php

namespace App\Services;

use App\Models\RediAdminDomain;

class DomainOriginalUrlGuard
{
    public static function isTakenGlobally(string $originalUrl, ?int $exceptDomainId = null): bool
    {
        $originalUrl = DomainRecordNormalizer::originalUrlIfValid($originalUrl);
        if ($originalUrl === '') {
            return false;
        }

        $hash = DomainRecordNormalizer::originalUrlHash($originalUrl);
        $query = RediAdminDomain::query()->withoutGlobalScope('admin_tenant');
        if ($hash !== '') {
            $query->where('original_url_hash', $hash);
        } else {
            $query->where('original_url', $originalUrl);
        }
        if ($exceptDomainId) {
            $query->where('id', '!=', $exceptDomainId);
        }

        return $query->exists();
    }
}
