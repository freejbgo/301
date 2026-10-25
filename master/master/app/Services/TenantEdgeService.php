<?php

namespace App\Services;

use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Support\HostNameValidator;
use App\Support\RediErrorMessage;
use Illuminate\Support\Str;

class TenantEdgeService
{
    public static function isPlaceholderCnameTarget(string $target): bool
    {
        return $target !== '' && isset($target[0]) && $target[0] === '(';
    }

    public static function ensureForAdmin(int $adminId): RediTenantEdge
    {
        $existing = RediTenantEdge::where('admin_id', $adminId)->first();
        if ($existing) {
            return $existing;
        }

        $platform = RediPlatformDns::instance();
        $zone = trim($platform->edge_zone, '.');

        $token = self::generateToken();
        $fqdn = '';
        if ($zone !== '') {
            $candidate = HostNameValidator::toCanonicalAscii(strtolower($token.'.'.$zone));
            if ($candidate !== '' && HostNameValidator::isValidHostLabel($candidate)) {
                $fqdn = $candidate;
            }
        }

        $edge = new RediTenantEdge();
        $edge->forceFill([
            'admin_id' => $adminId,
            'token' => $token,
            'edge_fqdn' => $fqdn,
        ]);
        $edge->save();

        return $edge;
    }

    public static function cnameTargetForAdmin(int $adminId): string
    {
        $edge = RediTenantEdge::where('admin_id', $adminId)->first();
        if ($edge && $edge->edge_fqdn) {
            return HostNameValidator::toCanonicalAscii((string) $edge->edge_fqdn);
        }

        $platform = RediPlatformDns::instance();
        if (trim($platform->edge_zone) === '') {
            return RediErrorMessage::get('edge.configure_zone_first');
        }

        return RediErrorMessage::get('edge.generated_after_save');
    }

    public static function rebuildAllEdgeFqdn(string $zone): void
    {
        $zone = HostNameValidator::toCanonicalAscii(strtolower(trim($zone, '.')));
        if ($zone === '' || ! HostNameValidator::isValidHostLabel($zone)) {
            return;
        }

        RediTenantEdge::query()->each(function (RediTenantEdge $edge) use ($zone) {
            $fqdn = HostNameValidator::toCanonicalAscii(strtolower($edge->token.'.'.$zone));
            $edge->edge_fqdn = HostNameValidator::isValidHostLabel($fqdn) ? $fqdn : '';
            $edge->dns_synced_at = null;
            $edge->save();
        });
    }

    /** Pool 主机名或解析目标变更后，避免仍显示「已同步」。 */
    public static function invalidateAllDnsSyncedAt(): void
    {
        RediTenantEdge::query()->update(['dns_synced_at' => null]);
    }

    protected static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(12));
        } while (RediTenantEdge::where('token', $token)->exists());

        return $token;
    }
}
