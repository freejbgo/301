<?php

namespace App\Admin\Forms;

use App\Services\DomainBatchCreator;
use Dcat\Admin\Admin;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;

class BatchCreateDomains extends Form
{
    public function handle(array $input)
    {
        $user = Admin::user();
        $creator = new DomainBatchCreator();

        $result = $creator->createFromTextLines(
            (int) $user->id,
            (string) $user->username,
            (int) ($input['group_id'] ?? 0),
            (int) ($input['status'] ?? 1),
            (string) ($input['lines'] ?? ''),
            $input['default_skip_url'] ?? null
        );

        if ($result['imported'] === 0 && $result['skipped'] === 0) {
            $message = $result['errors'] !== []
                ? implode("\n", array_slice($result['errors'], 0, 15))
                : '未新增任何域名';

            return $this->response()->error($message);
        }

        $summary = "新增 {$result['imported']} 条，跳过重复 {$result['skipped']} 条。";
        if ($result['errors'] !== []) {
            $summary .= "\n部分行未写入：\n".implode("\n", array_slice($result['errors'], 0, 10));
        }

        return $this->response()->success($summary)->refresh();
    }

    public function form()
    {
        $groupList = [];
        $groups = DB::table('redi_admin_group')
            ->where('admin_id', Admin::user()->id)
            ->where('status', 1)
            ->get(['id', 'name']);

        foreach ($groups as $group) {
            $groupList[$group->id] = $group->name;
        }

        $this->select('group_id', '分组')->options($groupList)->required();
        $this->radio('status', '状态')->options([1 => '启用', 2 => '停用'])->default(1);
        $this->text('default_skip_url', '统一跳转地址')
            ->help('若下面每行只写原始域名，将统一跳转到此地址；也可在每一行写「原始域名,跳转地址」覆盖。');
        $this->textarea('lines', '域名列表')
            ->rows(12)
            ->required()
            ->placeholder("示例：\nexample.com\nfoo.com,https://target.com\n# 井号开头为注释")
            ->help('每行一条。支持：仅域名；或 原始域名,跳转地址（逗号/竖线/Tab 分隔）。');
    }
}
