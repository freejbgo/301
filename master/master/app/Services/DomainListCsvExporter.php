<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DomainListCsvExporter
{
    /**
     * @return list<string>
     */
    public static function headerRow(): array
    {
        $labels = __('redi-admin-domain.export.headers');
        $row = [];
        foreach (self::headerColumnKeys() as $key) {
            $row[] = is_array($labels) ? (string) ($labels[$key] ?? $key) : $key;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public static function headerColumnKeys(): array
    {
        return [
            'id',
            'original_url',
            'skip_url',
            'status',
            'group_id',
            'admin_name',
            'cert_status',
            'cert_expires_at',
            'cname_target',
            'dns_points_to_platform',
            'force_https',
            'skip_num',
        ];
    }

    public static function streamedResponse(Request $request): StreamedResponse
    {
        $filename = 'domains-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::headerRow());

            $query = self::filteredQuery($request);
            foreach ($query->cursor() as $domain) {
                $cert = $domain->certificate;
                [$cnameTarget, $dnsOk] = self::dnsExportColumnsForDomain($domain);
                fputcsv($handle, [
                    $domain->id,
                    self::csvSafeCell((string) $domain->original_url),
                    self::csvSafeCell((string) $domain->skip_url),
                    (int) $domain->status === 1 ? 'enabled' : 'disabled',
                    $domain->group_id,
                    self::csvSafeCell((string) ($domain->admin_name ?? '')),
                    $cert ? $cert->status : 'none',
                    $cert && $cert->expires_at ? $cert->expires_at->format('Y-m-d') : '',
                    self::csvSafeCell($cnameTarget),
                    $dnsOk,
                    $domain->force_https ? '1' : '0',
                    $domain->skip_num,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Builder<RediAdminDomain>
     */
    public static function filteredQuery(Request $request): Builder
    {
        return DomainListFilter::filteredQuery($request);
    }

    /**
     * @return DnsExportColumns [cname_target, dns_points_to_platform yes|no|unknown]
     */
    public static function dnsExportColumnsForDomain(RediAdminDomain $domain): array
    {
        $host = DomainRecordNormalizer::originalUrlIfValid((string) $domain->original_url);
        if ($host === '') {
            return ['', 'unknown'];
        }

        TenantEdgeService::ensureForAdmin((int) $domain->admin_id);
        $edge = TenantEdgeService::cnameTargetForAdmin((int) $domain->admin_id);
        if ($edge === '' || TenantEdgeService::isPlaceholderCnameTarget($edge)) {
            return ['', 'unknown'];
        }

        $ok = CnamePrecheckService::pointsToEdgeCached($host, $edge);

        return [$edge, $ok ? 'yes' : 'no'];
    }

    /** 防止 Excel/Numbers 将单元格当作公式执行（CSV 注入）。 */
    public static function csvSafeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];

        return in_array($first, ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
