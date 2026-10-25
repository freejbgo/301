#!/usr/bin/env bash
# zh_CN / en / zh_TW 的 redi-* 与 Dcat menu|global|extension 键一致（与 RediLangParityTest 同逻辑，无 composer 亦可跑）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LANG_ROOT="${REPO_ROOT}/master/master/resources/lang"

fail() {
  echo "verify-redi-lang-parity: $*" >&2
  exit 1
}

command -v php >/dev/null 2>&1 || fail "php required"

export LANG_ROOT

php -d detect_unicode=0 <<'PHP'
<?php

$baseDir = getenv('LANG_ROOT');
if ($baseDir === false || $baseDir === '') {
    fwrite(STDERR, "verify-redi-lang-parity: LANG_ROOT not set\n");
    exit(1);
}

function flattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));
        } else {
            $keys[] = $path;
        }
    }
    sort($keys);

    return $keys;
}

function assertLangFileParity(string $baseDir, string $name): void
{
    $zhPath = $baseDir.'/zh_CN/'.$name;
    $enPath = $baseDir.'/en/'.$name;
    $twPath = $baseDir.'/zh_TW/'.$name;

    if (! is_file($zhPath)) {
        fwrite(STDERR, "verify-redi-lang-parity: missing zh_CN/{$name}\n");
        exit(1);
    }
    if (! is_file($enPath)) {
        fwrite(STDERR, "verify-redi-lang-parity: missing en/{$name}\n");
        exit(1);
    }
    if (! is_file($twPath)) {
        fwrite(STDERR, "verify-redi-lang-parity: missing zh_TW/{$name}\n");
        exit(1);
    }

    $zhKeys = flattenKeys(include $zhPath);
    $enKeys = flattenKeys(include $enPath);
    $twKeys = flattenKeys(include $twPath);

    if ($zhKeys !== $enKeys) {
        $onlyZh = array_values(array_diff($zhKeys, $enKeys));
        $onlyEn = array_values(array_diff($enKeys, $zhKeys));
        fwrite(STDERR, "verify-redi-lang-parity: en/{$name} keys mismatch\n");
        if ($onlyZh !== []) {
            fwrite(STDERR, '  only zh_CN: '.implode(', ', array_slice($onlyZh, 0, 20))."\n");
        }
        if ($onlyEn !== []) {
            fwrite(STDERR, '  only en: '.implode(', ', array_slice($onlyEn, 0, 20))."\n");
        }
        exit(1);
    }
    if ($zhKeys !== $twKeys) {
        fwrite(STDERR, "verify-redi-lang-parity: zh_TW/{$name} keys mismatch\n");
        exit(1);
    }
}

$rediFiles = glob($baseDir.'/zh_CN/redi-*.php') ?: [];
if ($rediFiles === []) {
    fwrite(STDERR, "verify-redi-lang-parity: no zh_CN/redi-*.php files\n");
    exit(1);
}

foreach ($rediFiles as $zhPath) {
    assertLangFileParity($baseDir, basename($zhPath));
}

foreach (['menu.php', 'global.php', 'extension.php'] as $shell) {
    assertLangFileParity($baseDir, $shell);
}

fwrite(STDOUT, "verify-redi-lang-parity: ok\n");
PHP
