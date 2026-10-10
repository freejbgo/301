<?php

namespace App\Admin\Forms;

use App\Services\DomainRecordNormalizer;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;

class UrlSetByGroup extends Form implements LazyRenderable
{
    use LazyWidget;
    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        // dump($input);

        // return $this->response()->error('Your error message.');

        $groupId = (int) ($input['id'] ?? 0);
        $adminId = (int) Admin::user()->id;
        $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($input['skip_url'] ?? '');

        if ($groupId <= 0) {
            return $this->response()->error('分组id异常');
        }
        if ($skipUrl === '') {
            return $this->response()->error('跳转链接异常');
        }

        $ownsGroup = DB::table('redi_admin_group')
            ->where('id', $groupId)
            ->where('admin_id', $adminId)
            ->exists();

        if (! $ownsGroup) {
            return $this->response()->error('分组无效或不属于当前账号');
        }

        DB::table('redi_admin_domain')
            ->where('group_id', $groupId)
            ->where('admin_id', $adminId)
            ->update(['skip_url' => $skipUrl]);

        return $this
				->response()
				->success('Processed successfully.')
				->refresh();
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        $this->confirm('您确定要提交表单吗', 'content');

        $this->text('skip_url', '跳转链接')->required();
        $this->hidden('id');
    }

    // 返回表单数据，如不需要可以删除此方法
    public function default()
    {
        return [
            'id' => $this->payload['id'] ?? 0,
        ];
    }

}
