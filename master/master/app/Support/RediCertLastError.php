<?php

namespace App\Support;

use App\Models\RediDomainCert;
use Illuminate\Support\Facades\Schema;

/**
 * Stable cert last_error codes (redi-errors keys). Human text stays in last_error for ops/admin display.
 */
final class RediCertLastError
{
    public const DOMAIN_DISABLED_CANCEL = 'deploy.domain_disabled_cancel';

    public const NODE_DISABLED_CLEARED = 'deploy.node_disabled_cleared';

    public const CREDENTIAL_UNREADABLE = 'cert.credential_unreadable';

    /** @deprecated Use {@see self::DOMAIN_DISABLED_CANCEL} and {@see messageForCode()} */
    public const LEGACY_DOMAIN_DISABLED_CANCEL_MSG = 'Domain disabled; cancelled certificate deploy queue on nodes';

    public static function messageForCode(string $code, array $replace = []): string
    {
        return RediErrorMessage::get($code, $replace);
    }

    public static function apply(RediDomainCert $cert, string $code, array $replace = [], ?string $message = null): void
    {
        if (Schema::hasColumn($cert->getTable(), 'last_error_code')) {
            $cert->last_error_code = $code;
        }
        $cert->last_error = $message ?? self::messageForCode($code, $replace);
    }

    /**
     * Free-form ops text (e.g. ACME exception snippet) with no stable code.
     */
    public static function applyRaw(RediDomainCert $cert, string $message, ?string $code = null): void
    {
        if (Schema::hasColumn($cert->getTable(), 'last_error_code')) {
            $cert->last_error_code = $code;
        }
        $cert->last_error = $message;
    }

    public static function clear(RediDomainCert $cert): void
    {
        if (Schema::hasColumn($cert->getTable(), 'last_error_code')) {
            $cert->last_error_code = null;
        }
        $cert->last_error = null;
    }

    public static function matches(RediDomainCert $cert, string $code): bool
    {
        if (Schema::hasColumn($cert->getTable(), 'last_error_code')
            && is_string($cert->last_error_code)
            && $cert->last_error_code !== ''
            && $cert->last_error_code === $code) {
            return true;
        }

        $expected = self::messageForCode($code);
        if ((string) $cert->last_error === $expected) {
            return true;
        }

        if ($code === self::DOMAIN_DISABLED_CANCEL
            && (string) $cert->last_error === self::LEGACY_DOMAIN_DISABLED_CANCEL_MSG) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function declaredCodes(): array
    {
        $codes = [];
        $ref = new \ReflectionClass(self::class);
        foreach ($ref->getReflectionConstants() as $constant) {
            if (! $constant->isPublic() || $constant->getName() === 'LEGACY_DOMAIN_DISABLED_CANCEL_MSG') {
                continue;
            }
            $value = $constant->getValue();
            if (is_string($value) && str_contains($value, '.')) {
                $codes[] = $value;
            }
        }

        sort($codes);

        return $codes;
    }
}
