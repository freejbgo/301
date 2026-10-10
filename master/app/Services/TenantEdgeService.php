<?php

namespace App\Services;

use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use Illuminate\Support\Str;

class TenantEdgeService
{
    public static function ensureForAdmin(int $adminId): RediTenantEdge
    {
        $existing = RediTenantEdge::where('admin_id', $adminId)->first();
        if ($existing) {
            return $existing;
        }

        $platform = RediPlatformDns::instance();
        $zone = trim($platform->edge_zone, '.');
        if ($zone === '') {
            $zone = '请先在 DNS 与节点池 配置平台主域名';
        }

        $token = self::generateToken();
        $fqdn = $zone === '请先在 DNS 与节点池 配置平台主域名'
            ? ''
            : strtolower($token.'.'.$zone);

        return RediTenantEdge::create([
            'admin_id' => $adminId,
            'token' => $token,
            'edge_fqdn' => $fqdn,
        ]);
    }

    public static function cnameTargetForAdmin(int $adminId): string
    {
        $edge = RediTenantEdge::where('admin_id', $adminId)->first();
        if ($edge && $edge->edge_fqdn) {
            return $edge->edge_fqdn;
        }

        $platform = RediPlatformDns::instance();
        if (trim($platform->edge_zone) === '') {
            return '（请先配置平台主域名）';
        }

        return '（保存域名后将自动生成）';
    }

    protected static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(12));
        } while (RediTenantEdge::where('token', $token)->exists());

        return $token;
    }
}
