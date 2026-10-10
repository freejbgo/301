<?php

namespace App\Admin\Actions\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DomainImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'group_id（分组ID，须为本账号下分组）',
            'original_url（原始域名，不含 http://）',
            'skip_url（跳转地址，可写域名或完整 URL）',
            'status（1启用 2停用）',
        ];
    }

    public function array(): array
    {
        return [
            [1, 'example.com', 'https://target.example.com', 1],
        ];
    }
}
