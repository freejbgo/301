<?php

namespace App\Support;

use InvalidArgumentException;

class AcmeHttp01Token
{
    public static function normalize(string $token): string
    {
        if (OpsDisplaySanitizer::containsControlChars($token)) {
            throw new InvalidArgumentException('Invalid ACME challenge token');
        }
        $token = trim($token);
        if ($token === '' || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new InvalidArgumentException('Invalid ACME challenge token');
        }

        return $token;
    }

    public static function isValid(string $token): bool
    {
        try {
            self::normalize($token);

            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }
}
