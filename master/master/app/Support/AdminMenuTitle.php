<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

class AdminMenuTitle
{
    /**
     * Resolve a sidebar menu label from DB title and optional route URI.
     */
    public static function resolve(string $dbTitle, ?string $uri): string
    {
        $dbTitle = trim($dbTitle);
        $viaTitle = self::translateByDcatTitleKey($dbTitle);
        if ($viaTitle !== $dbTitle) {
            return $viaTitle;
        }

        $slug = self::uriLangSlug($uri);
        if ($slug !== '') {
            $uriKey = 'menu.uris.'.$slug;
            if (Lang::has($uriKey)) {
                return (string) __($uriKey);
            }
        }

        return $dbTitle;
    }

    public static function uriLangSlug(?string $uri): string
    {
        $uri = trim((string) $uri, '/');
        if ($uri === '') {
            return '';
        }

        return str_replace('/', '.', $uri);
    }

    protected static function translateByDcatTitleKey(string $text): string
    {
        $titleTranslation = 'menu.titles.'.trim(str_replace(' ', '_', strtolower($text)));
        if (Lang::has($titleTranslation)) {
            return (string) __($titleTranslation);
        }

        return $text;
    }
}
