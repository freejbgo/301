<?php

namespace App\Services\Dns\Concerns;

use App\Support\RediErrorMessage;

use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use RuntimeException;

trait EnsuresValidDnsNames
{
    protected function requireDnsFqdn(string $fqdn, string $label = 'DNS FQDN'): string
    {
        if (OpsDisplaySanitizer::containsControlChars($fqdn)) {
            throw new RuntimeException(RediErrorMessage::get('dns.label_invalid', ['label' => $label]));
        }
        $fqdn = HostNameValidator::toCanonicalAscii($fqdn);
        if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)) {
            throw new RuntimeException(RediErrorMessage::get('dns.label_invalid', ['label' => $label]));
        }

        return strtolower(rtrim($fqdn, '.'));
    }

    protected function requireDnsRrLabel(string $name): string
    {
        if (OpsDisplaySanitizer::containsControlChars($name)) {
            throw new RuntimeException(RediErrorMessage::get('dns.host_invalid'));
        }
        $name = strtolower(trim($name, '.'));
        if ($name === '' || $name === '@') {
            return '@';
        }
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
            throw new RuntimeException(RediErrorMessage::get('dns.host_invalid'));
        }

        return $name;
    }
}
