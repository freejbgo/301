<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DomainListCsvExporter
{
    public static function streamedResponse(Request $request): StreamedResponse
    {
        $filename = 'domains-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
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
            ]);

            $query = self::filteredQuery($request);
            foreach ($query->cursor() as $domain) {
                $cert = $domain->certificate;
                [$cnameTarget, $dnsOk] = self::dnsExportColumnsForDomain($domain);
                fputcsv($handle, [
                    $domain->id,
                    $domain->original_url,
                    $domain->skip_url,
                    (int) $domain->status === 1 ? 'enabled' : 'disabled',
                    $domain->group_id,
                    $domain->admin_name,
                    $cert ? $cert->status : 'none',
                    $cert && $cert->expires_at ? $cert->expires_at->format('Y-m-d') : '',
                    $cnameTarget,
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
        $query = RediAdminDomain::query()
            ->with('certificate')
            ->orderBy('id');

        if ($request->filled('group_id')) {
            $query->where('group_id', (int) $request->input('group_id'));
        }
        if ($request->filled('original_url')) {
            $query->where('original_url', 'like', '%'.addcslashes((string) $request->input('original_url'), '%_\\').'%');
        }
        if ($request->filled('skip_url')) {
            $query->where('skip_url', 'like', '%'.addcslashes((string) $request->input('skip_url'), '%_\\').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('cert_filter')) {
            RediAdminDomain::applyCertificateListFilter($query, (string) $request->input('cert_filter'));
        }

        return $query;
    }

    /**
     * @return DnsExportColumns [cname_target, dns_points_to_platform yes|no|unknown]
     */
    public static function dnsExportColumnsForDomain(RediAdminDomain $domain): array
    {
        if (! DomainRecordNormalizer::isValidOriginalHost((string) $domain->original_url)) {
            return ['', 'unknown'];
        }

        TenantEdgeService::ensureForAdmin((int) $domain->admin_id);
        $edge = TenantEdgeService::cnameTargetForAdmin((int) $domain->admin_id);
        if ($edge === '' || strncmp($edge, '（', 3) === 0) {
            return ['', 'unknown'];
        }

        $ok = CnamePrecheckService::pointsToEdgeCached($domain->original_url, $edge);

        return [$edge, $ok ? 'yes' : 'no'];
    }
}
