#!/usr/bin/env bash
# en/redi-errors.php：RediErrorMessage::get 所用键必须存在；语言文件键不得无引用（防死键堆积）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ROOT="${REPO_ROOT}/master/master"
ERRORS_FILE="${APP_ROOT}/resources/lang/en/redi-errors.php"

fail() {
  echo "verify-redi-errors-invariants: $*" >&2
  exit 1
}

command -v php >/dev/null 2>&1 || fail "php required"
command -v rg >/dev/null 2>&1 || fail "ripgrep (rg) required"
[[ -f "$ERRORS_FILE" ]] || fail "missing $ERRORS_FILE"

export APP_ROOT
export ERRORS_FILE

php -d detect_unicode=0 <<'PHP'
<?php

$appRoot = getenv('APP_ROOT');
$errorsFile = getenv('ERRORS_FILE');

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

$defined = flattenKeys(include $errorsFile);

$grepCmd = sprintf(
    'rg -o %s %s -g *.php',
    escapeshellarg("RediErrorMessage::get\\('([^']+)'"),
    escapeshellarg($appRoot.'/app').' '.escapeshellarg($appRoot.'/tests')
);
exec($grepCmd, $lines, $code);
if ($code > 1) {
    fwrite(STDERR, "verify-redi-errors-invariants: rg failed\n");
    exit(1);
}

$used = [];
foreach ($lines as $line) {
    if (preg_match("/RediErrorMessage::get\\('([^']+)'/", $line, $m)) {
        $used[$m[1]] = true;
    }
}

$codePatterns = [
    "RediCertLastError::apply\\([^,]+,\\s*'([^']+)'",
    "refuseQueueDeploy\\([^,]+,\\s*'([^']+)'",
    "reconcileCertWithNoDeploymentRows\\([^,]+,\\s*'([^']+)'",
    "RediCertLastError::applyRaw\\([^,]+,[^,]+,\\s*'([^']+)'",
];
foreach ($codePatterns as $pattern) {
    $grep = sprintf(
        'rg -o %s %s -g *.php',
        escapeshellarg($pattern),
        escapeshellarg($appRoot.'/app')
    );
    exec($grep, $codeLines, $codeExit);
    if ($codeExit > 1) {
        fwrite(STDERR, "verify-redi-errors-invariants: rg failed for cert error codes\n");
        exit(1);
    }
    foreach ($codeLines as $line) {
        if (preg_match("/'([^']+)'/", $line, $m)) {
            $used[$m[1]] = true;
        }
    }
}

require_once $appRoot.'/app/Support/RediCertLastError.php';
foreach (\App\Support\RediCertLastError::declaredCodes() as $declared) {
    $used[$declared] = true;
}

$certErrorFiles = [
    $appRoot.'/app/Support/RediCertLastError.php',
    $appRoot.'/app/Services/CertificateDeployService.php',
    $appRoot.'/app/Jobs/IssueDomainCertificateJob.php',
    $appRoot.'/app/Models/RediAdminDomain.php',
    $appRoot.'/app/Models/RediDomainCert.php',
];
foreach ($certErrorFiles as $file) {
    if (! is_file($file)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    if (preg_match_all("/'((?:cert|deploy)\\.[a-z0-9_]+)'/", $content, $matches)) {
        foreach ($matches[1] as $code) {
            if ($code === 'cert.domain') {
                continue;
            }
            $used[$code] = true;
        }
    }
}

$usedKeys = array_keys($used);
sort($usedKeys);

$definedSet = array_fill_keys($defined, true);
foreach ($usedKeys as $key) {
    if (! isset($definedSet[$key])) {
        fwrite(STDERR, "verify-redi-errors-invariants: missing en/redi-errors key: {$key}\n");
        exit(1);
    }
}

$usedSet = array_fill_keys($usedKeys, true);
foreach ($defined as $key) {
    if (! isset($usedSet[$key])) {
        fwrite(STDERR, "verify-redi-errors-invariants: unused en/redi-errors key: {$key}\n");
        exit(1);
    }
}

fwrite(STDOUT, "verify-redi-errors-invariants: ok\n");
PHP
