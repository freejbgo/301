<?php

namespace App\Services\Dns\Concerns;

use App\Support\HostNameValidator;
use RuntimeException;

trait EnsuresValidDnsNames
{
    protected function requireDnsFqdn(string $fqdn, string $label = 'DNS FQDN'): string
    {
        $fqdn = HostNameValidator::toCanonicalAscii($fqdn);
        if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)) {
            throw new RuntimeException($label.' 无效');
        }

        return strtolower(rtrim($fqdn, '.'));
    }

    protected function requireDnsRrLabel(string $name): string
    {
        $name = strtolower(trim($name, '.'));
        if ($name === '' || $name === '@') {
            return '@';
        }
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
            throw new RuntimeException('DNS 主机记录名无效');
        }

        return $name;
    }
}
