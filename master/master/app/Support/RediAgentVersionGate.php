<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class RediAgentVersionGate
{
    public static function sanitize(?string $value): ?string
    {
        $raw = (string) $value;
        if (OpsDisplaySanitizer::containsControlChars($raw)) {
            return null;
        }
        $version = preg_replace('/[^\w.\-]/', '', $raw);
        $version = substr($version, 0, 32);

        return $version === '' ? null : $version;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function resolveFromInput(array $input, ?string $storedVersion = null): ?string
    {
        if (array_key_exists('version', $input)) {
            return self::sanitize($input['version']);
        }

        return $storedVersion !== null ? self::sanitize($storedVersion) : null;
    }

    public static function rejectResponse(?string $version): ?JsonResponse
    {
        $min = trim((string) config('redi.min_agent_version', ''));
        if ($min === '') {
            return null;
        }

        if ($version === null || $version === '') {
            return response()->json([
                'message' => '节点 Agent 必须上报 version 字段，请升级 redi-multi 后重试',
                'min_version' => $min,
            ], 426);
        }

        if (version_compare($version, $min, '<')) {
            return response()->json([
                'message' => '节点 Agent 版本过低，请升级 redi-multi 后重试',
                'min_version' => $min,
            ], 426);
        }

        return null;
    }
}
