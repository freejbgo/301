<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediAdminGroup;

class DomainBatchCreator
{
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
                $result['errors'][] = "第 {$rowIndex} 行：原始域名不能为空";
                continue;
            }

            if ($skipUrl === '') {
                $result['errors'][] = "第 {$rowIndex} 行（{$originalUrl}）：缺少跳转地址，请填写「统一跳转地址」或在本行写「域名,跳转地址」";
                continue;
            }

            $exists = RediAdminDomain::query()
                ->where('admin_id', $adminId)
                ->where('original_url', $originalUrl)
                ->exists();

            if ($exists) {
                $result['skipped']++;
                continue;
            }

            RediAdminDomain::create([
                'group_id' => $groupId,
                'original_url' => $originalUrl,
                'skip_url' => $skipUrl,
                'status' => $status,
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'skip_num' => 0,
            ]);
            $result['imported']++;
        }

        if ($rowIndex === 0) {
            $result['errors'][] = '请至少填写一行域名';
        }

        return $result;
    }
}
