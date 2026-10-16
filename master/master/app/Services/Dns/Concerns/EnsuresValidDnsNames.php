<?php

namespace App\Services\Dns\Concerns;

use App\Support\HostNameValidator;
use RuntimeException;

trait EnsuresValidDnsNames
{
    protected function requireDnsFqdn(string $fqdn, string $label = 'DNS FQDN'): string
    {
        if ($this->dnsNameContainsControlChars($fqdn)) {
            throw new RuntimeException($label.' 无效');
        }
        $fqdn = HostNameValidator::toCanonicalAscii($fqdn);
        if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)) {
            throw new RuntimeException($label.' 无效');
        }

        return strtolower(rtrim($fqdn, '.'));
    }

    protected function requireDnsRrLabel(string $name): string
    {
        if ($this->dnsNameContainsControlChars($name)) {
            throw new RuntimeException('DNS 主机记录名无效');
        }
        $name = strtolower(trim($name, '.'));
        if ($name === '' || $name === '@') {
            return '@';
        }
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
            throw new RuntimeException('DNS 主机记录名无效');
        }

        return $name;
    }

    protected function dnsNameContainsControlChars(string $value): bool
    {
        return str_contains($value, "\r")
            || str_contains($value, "\n")
            || str_contains($value, "\0")
            || str_contains($value, "\t");
    }
}
