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

        $max = DomainBatchCreator::MAX_LINES_PER_BATCH;

        $this->select('group_id', '分组')->options($groupList)->required();
        $this->radio('status', '状态')->options([1 => '启用', 2 => '停用'])->default(1);
        $this->text('default_skip_url', '统一跳转地址')
            ->help('方式一：填写此项后，下面每行可只写原始域名，全部跳到同一地址。');

        $placeholder = <<<TXT
【填写说明，点击输入后本提示消失】
· 单次最多 {$max} 条（一行一条，空行不算；# 开头为注释）
· 方式一：上面填「统一跳转地址」，这里每行只写原始域名，如：example.com
· 方式二：每行写「原始域名,跳转地址」，逗号/竖线/Tab 均可，如：foo.com,https://a.com
· 原始域名不要带 http://；本账号已存在的域名会自动跳过
· 本批共用上方所选分组与状态

示例：
example.com
foo.com,https://target.example.com
# 下面再写更多域名…
TXT;

        $this->textarea('lines', '域名列表')
            ->rows(14)
            ->required()
            ->placeholder($placeholder)
            ->help("最多 {$max} 条/次。点击输入框后灰色提示会消失，属正常现象。");
    }
}
