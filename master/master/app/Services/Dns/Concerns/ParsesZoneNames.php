<?php

namespace App\Services\Dns\Concerns;

use Illuminate\Support\Str;

trait ParsesZoneNames
{
    /**
     * @return array{rr: string, domain: string}
     */
    protected function splitFqdn(string $fqdn, string $zone): array
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        $zone = strtolower(rtrim($zone, '.'));

        if ($zone !== '' && Str::endsWith($fqdn, '.'.$zone)) {
            $rr = substr($fqdn, 0, -strlen('.'.$zone));
            if ($rr === '') {
                $rr = '@';
            }

            return ['rr' => $rr, 'domain' => $zone];
        }

        return ['rr' => '@', 'domain' => $zone ?: $fqdn];
    }
}
