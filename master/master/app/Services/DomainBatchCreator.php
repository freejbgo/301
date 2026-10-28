<?php

namespace App\Services;

use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;
use App\Models\RediAdminDomain;
use App\Models\RediAdminGroup;
use Illuminate\Database\QueryException;

class DomainBatchCreator
{
    /** 单次批量添加上限（有效数据行，不含空行与 # 注释） */
    public const MAX_LINES_PER_BATCH = 500;

    /**
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function createFromTextLines(
        int $adminId,
        string $adminName,
        int $groupId,
        int $status,
        string $lines,
        ?string $defaultSkipUrl = null
    ): array {
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        if (! in_array($status, [1, 2], true)) {
            $result['errors'][] = __('redi-batch-domains.errors.status_invalid');

            return $result;
        }

        $groupOk = RediAdminGroup::query()
            ->where('admin_id', $adminId)
            ->where('id', $groupId)
            ->exists();

        if (! $groupOk) {
            $result['errors'][] = __('redi-batch-domains.errors.group_invalid');

            return $result;
        }

        TenantEdgeService::ensureForAdmin($adminId);

        $dataLineCount = $this->countDataLines($lines);
        if ($dataLineCount === 0) {
            $result['errors'][] = __('redi-batch-domains.errors.no_lines');

            return $result;
        }
        if ($dataLineCount > self::MAX_LINES_PER_BATCH) {
            $result['errors'][] = __('redi-batch-domains.errors.too_many_lines', [
                'max' => self::MAX_LINES_PER_BATCH,
                'count' => $dataLineCount,
            ]);

            return $result;
        }

        $defaultSkip = DomainRecordNormalizer::normalizeSkipUrl($defaultSkipUrl ?? '');
        $rowIndex = 0;

        foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }

            $rowIndex++;
            $originalUrl = '';
            $skipUrl = '';

            if (preg_match('/[,\t|]/u', $line)) {
                $parts = preg_split('/[,\t|]/u', $line, 2);
                $rawOriginal = $parts[0] ?? '';
                $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($parts[1] ?? '');
            } else {
                $rawOriginal = $line;
                $skipUrl = $defaultSkip;
            }

            $originalUrl = DomainRecordNormalizer::originalUrlIfValid($rawOriginal);
            if ($originalUrl === '') {
                $attempt = DomainRecordNormalizer::normalizeOriginalUrl($rawOriginal);
                if ($attempt === '') {
                    $result['errors'][] = __('redi-batch-domains.errors.row_original_empty', ['row' => $rowIndex]);
                } else {
                    $label = OpsDisplaySanitizer::field($attempt, '—', 253);
                    $result['errors'][] = __('redi-batch-domains.errors.row_original_invalid', [
                        'row' => $rowIndex,
                        'label' => $label,
                    ]);
                }
                continue;
            }

            if ($skipUrl === '') {
                $label = OpsDisplaySanitizer::field($originalUrl, '—', 253);
                $result['errors'][] = __('redi-batch-domains.errors.row_skip_missing', [
                    'row' => $rowIndex,
                    'label' => $label,
                ]);
                continue;
            }

            if (DomainOriginalUrlGuard::isTakenGlobally($originalUrl)) {
                $result['skipped']++;
                continue;
            }

            if ($status === 1
                && DomainDnsPointingGuard::mustPointToEdgeBeforeEnable()
                && ! AdminTenantContext::isPlatformAdministrator()
                && ! DomainDnsPointingGuard::pointsToTenantEdge($adminId, $originalUrl)) {
                $edge = TenantEdgeService::cnameTargetForAdmin($adminId);
                $label = OpsDisplaySanitizer::field($originalUrl, '—', 253);
                $edgeLabel = OpsDisplaySanitizer::field($edge, '—', 253);
                $result['errors'][] = __('redi-batch-domains.errors.row_dns_required', [
                    'row' => $rowIndex,
                    'label' => $label,
                    'edge' => $edgeLabel,
                ]);
                continue;
            }

            try {
                $domain = new RediAdminDomain();
                $domain->forceFill([
                    'group_id' => $groupId,
                    'original_url' => $originalUrl,
                    'skip_url' => $skipUrl,
                    'status' => $status,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'skip_num' => 0,
                ]);
                $domain->save();
                $result['imported']++;
            } catch (QueryException $e) {
                if ($this->isDuplicateOriginalUrl($e)) {
                    $result['skipped']++;
                } else {
                    $label = OpsDisplaySanitizer::field($originalUrl, '—', 253);
                    $result['errors'][] = __('redi-batch-domains.errors.row_write_failed', [
                        'row' => $rowIndex,
                        'label' => $label,
                    ]);
                }
            }
        }

        return $result;
    }

    protected function countDataLines(string $lines): int
    {
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    protected function isDuplicateOriginalUrl(QueryException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $code === 1062;
    }
}
