<?php

namespace App\Services;

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
            $result['errors'][] = '状态只能是启用或停用';

            return $result;
        }

        $groupOk = RediAdminGroup::query()
            ->where('admin_id', $adminId)
            ->where('id', $groupId)
            ->exists();

        if (! $groupOk) {
            $result['errors'][] = '分组无效或不属于当前账号';

            return $result;
        }

        TenantEdgeService::ensureForAdmin($adminId);

        $dataLineCount = $this->countDataLines($lines);
        if ($dataLineCount === 0) {
            $result['errors'][] = '请至少填写一行域名';

            return $result;
        }
        if ($dataLineCount > self::MAX_LINES_PER_BATCH) {
            $result['errors'][] = '单次最多添加 '.self::MAX_LINES_PER_BATCH.' 条域名，当前为 '.$dataLineCount.' 条，请分批提交。';

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
                $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($parts[0] ?? '');
                $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($parts[1] ?? '');
            } else {
                $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($line);
                $skipUrl = $defaultSkip;
            }

            if ($originalUrl === '') {
                $result['errors'][] = "第 {$rowIndex} 行：原始域名不能为空或含路径";
                continue;
            }
            if (! DomainRecordNormalizer::isValidOriginalHost($originalUrl)) {
                $result['errors'][] = "第 {$rowIndex} 行（{$originalUrl}）：原始域名格式无效";
                continue;
            }

            if ($skipUrl === '') {
                $result['errors'][] = "第 {$rowIndex} 行（{$originalUrl}）：缺少跳转地址，请填写「统一跳转地址」或在本行写「域名,跳转地址」";
                continue;
            }

            if (DomainOriginalUrlGuard::isTakenGlobally($originalUrl)) {
                $result['skipped']++;
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
                    $result['errors'][] = "第 {$rowIndex} 行（{$originalUrl}）：写入失败";
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
