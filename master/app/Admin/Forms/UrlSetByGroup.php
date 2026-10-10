<?php

namespace App\Admin\Forms;

use Dcat\Admin\Widgets\Form;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Contracts\LazyRenderable;
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

        if(empty($input['id'])){
            return $this->response()->error('分组id异常');
        }
        if(empty($input['skip_url'])){
            return $this->response()->error('跳转链接异常');
        }

        $ret = DB::table("redi_admin_domain")->where('group_id', $input['id'])->update(['skip_url'=>$input['skip_url']]);

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
