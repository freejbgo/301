<?php

namespace App\Services;

class DomainRecordNormalizer
{
    public static function normalizeOriginalUrl(?string $url): string
    {
        $url = trim((string) $url);
        $url = preg_replace('#^https?://#i', '', $url);

        return rtrim($url, '/');
    }

    public static function normalizeSkipUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return 'http://'.$url;
    }
}
