<?php

namespace App\Admin\Actions\Imports;

use App\Models\RediAdminDomain;
use App\Models\RediAdminGroup;
use App\Services\DomainRecordNormalizer;
use Dcat\Admin\Admin;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class DomainImport implements ToCollection, WithStartRow
{
    protected int $adminId;

    protected string $adminName;

    /** @var array<int, true> */
    protected array $allowedGroupIds = [];

    public function __construct()
    {
        $user = Admin::user();
        $this->adminId = (int) $user->id;
        $this->adminName = (string) $user->username;
        $this->allowedGroupIds = RediAdminGroup::query()
            ->where('admin_id', $this->adminId)
            ->pluck('id')
            ->flip()
            ->all();
    }

    public function collection(Collection $rows): void
    {
        $errors = [];
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $line = $index + $this->startRow();
            $cells = $row->toArray();

            if ($this->rowIsEmpty($cells)) {
                continue;
            }

            $groupId = (int) ($cells[0] ?? 0);
            $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($cells[1] ?? '');
            $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($cells[2] ?? '');
            $status = (int) ($cells[3] ?? 1);

            if ($groupId <= 0 || ! isset($this->allowedGroupIds[$groupId])) {
                $errors[] = "第 {$line} 行：分组 ID 无效或不属于当前账号（group_id={$groupId}）";
                continue;
            }
            if ($originalUrl === '') {
                $errors[] = "第 {$line} 行：原始域名不能为空";
                continue;
            }
            if ($skipUrl === '') {
                $errors[] = "第 {$line} 行：跳转地址不能为空";
                continue;
            }
            if (! in_array($status, [1, 2], true)) {
                $errors[] = "第 {$line} 行：状态只能是 1（启用）或 2（停用）";
                continue;
            }

            $exists = RediAdminDomain::query()
                ->where('admin_id', $this->adminId)
                ->where('original_url', $originalUrl)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            RediAdminDomain::create([
                'group_id' => $groupId,
                'original_url' => $originalUrl,
                'skip_url' => $skipUrl,
                'status' => $status,
                'admin_id' => $this->adminId,
                'admin_name' => $this->adminName,
                'skip_num' => 0,
            ]);
            $imported++;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => array_merge(
                    $errors,
                    ["导入中止：成功 {$imported} 条，跳过重复 {$skipped} 条。"]
                ),
            ]);
        }

        if ($imported === 0 && $skipped === 0) {
            throw ValidationException::withMessages([
                'file' => ['未导入任何数据，请检查 Excel 是否从第 2 行开始填写。'],
            ]);
        }

        session()->flash('domain_import_summary', "导入完成：新增 {$imported} 条，跳过重复 {$skipped} 条。");
    }

    public function startRow(): int
    {
        return 2;
    }

    protected function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
