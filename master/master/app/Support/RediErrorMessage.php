<?php

namespace App\Support;

/**
 * Canonical English operational messages (stored in DB, CLI, webhooks, last_error).
 * Admin screens use localize() so the same text follows zh_CN / zh_TW / en.
 */
final class RediErrorMessage
{
    /** @var array<string, string>|null */
    private static $englishLines;

    public static function get(string $key, array $replace = []): string
    {
        return (string) trans('redi-errors.'.$key, $replace, 'en');
    }

    /**
     * Show a stored English operational message in the current admin language.
     * The database value stays English. Dynamic tails (host, URL, exception text) are kept.
     */
    public static function localize(?string $code, ?string $stored): string
    {
        $stored = (string) $stored;
        $locale = (string) app()->getLocale();
        if ($locale === '' || $locale === 'en') {
            return $stored !== '' ? $stored : self::fallbackFromCode($code, 'en');
        }

        if ($stored === '') {
            return self::fallbackFromCode($code, $locale);
        }

        $translated = self::translateStored($stored, $locale);
        if ($translated !== $stored) {
            return $translated;
        }

        $fromCode = self::fallbackFromCode($code, $locale);

        return $fromCode !== '' ? $fromCode : $stored;
    }

    private static function fallbackFromCode(?string $code, string $locale): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $english = trans('redi-errors.'.$code, [], 'en');
        if (! is_string($english) || $english === 'redi-errors.'.$code || str_contains($english, ':')) {
            return '';
        }

        $line = trans('redi-errors.'.$code, [], $locale);
        if (! is_string($line) || $line === 'redi-errors.'.$code) {
            return '';
        }

        return $line;
    }

    private static function translateStored(string $text, string $locale): string
    {
        if ($text === '') {
            return '';
        }

        $prefix = self::bestMatch($text, 12, true);
        if ($prefix !== null && $prefix['pos'] === 0) {
            return self::render($prefix, $locale).self::translateStored(substr($text, $prefix['len']), $locale);
        }

        $inner = self::bestMatch($text, 24, false);
        if ($inner === null) {
            return $text;
        }

        return substr($text, 0, $inner['pos'])
            .self::render($inner, $locale)
            .self::translateStored(substr($text, $inner['pos'] + $inner['len']), $locale);
    }

    /**
     * @return array{pos: int, len: int, key: string, replace: array<string, string>, raw: string}|null
     */
    private static function bestMatch(string $text, int $minLength, bool $prefixOnly): ?array
    {
        $best = null;
        foreach (self::englishLines() as $key => $template) {
            if (strlen($template) < $minLength) {
                continue;
            }
            $found = self::findTemplate($template, $text, $prefixOnly);
            if ($found === null) {
                continue;
            }
            $found['key'] = $key;
            if ($best === null
                || $found['pos'] < $best['pos']
                || ($found['pos'] === $best['pos'] && $found['len'] > $best['len'])) {
                $best = $found;
            }
        }

        return $best;
    }

    /**
     * @return array{pos: int, len: int, replace: array<string, string>, raw: string}|null
     */
    private static function findTemplate(string $template, string $text, bool $prefixOnly): ?array
    {
        $parts = preg_split('/(:[A-Za-z_][A-Za-z0-9_]*)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return null;
        }

        $regex = $prefixOnly ? '^' : '';
        $names = [];
        foreach ($parts as $part) {
            if (preg_match('/^:([A-Za-z_][A-Za-z0-9_]*)$/', $part, $name)) {
                $names[] = $name[1];
                $regex .= '(?P<'.$name[1].'>.+?)';
                continue;
            }
            $regex .= preg_quote($part, '/');
        }

        $flags = $prefixOnly ? '' : 'u';
        if (! preg_match('/'.$regex.'/s'.$flags, $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $replace = [];
        foreach ($names as $name) {
            $replace[$name] = (string) $match[$name][0];
        }

        return [
            'pos' => (int) $match[0][1],
            'len' => strlen((string) $match[0][0]),
            'replace' => $replace,
            'raw' => (string) $match[0][0],
        ];
    }

    /**
     * @param  array{key: string, replace: array<string, string>, raw: string}  $match
     */
    private static function render(array $match, string $locale): string
    {
        $line = trans('redi-errors.'.$match['key'], $match['replace'], $locale);
        if (! is_string($line) || $line === '' || $line === 'redi-errors.'.$match['key']) {
            return $match['raw'];
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private static function englishLines(): array
    {
        if (self::$englishLines !== null) {
            return self::$englishLines;
        }

        $tree = trans('redi-errors', [], 'en');
        $flat = [];
        if (is_array($tree)) {
            self::flatten($tree, '', $flat);
        }
        self::$englishLines = $flat;

        return self::$englishLines;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, string>  $flat
     */
    private static function flatten(array $tree, string $prefix, array &$flat): void
    {
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                self::flatten($value, $path, $flat);
                continue;
            }
            if (is_string($value) && $value !== '') {
                $flat[$path] = $value;
            }
        }
    }
}
