<?php

namespace App\Admin\Actions\Form;

use App\Admin\Actions\Imports\DomainImport;
use Dcat\Admin\Widgets\Form;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class memberForm extends Form
{
    public function handle(array $input)
    {
        try {
            $file = storage_path('app/public/'.$input['file']);
            Excel::import(new DomainImport(), $file);

            $summary = session('domain_import_summary', '数据导入成功');

            return $this->response()->success($summary)->refresh();
        } catch (ValidationException $e) {
            $messages = $e->validator->errors()->get('file');

            return $this->response()->error(implode("\n", $messages));
        } catch (\Throwable $e) {
            return $this->response()->error($e->getMessage());
        }
    }

    public function form()
    {
        $templateUrl = admin_url('domain/import-template');

        $this->html(
            <<<HTML
<p class="text-muted mb-2">
  请使用 Excel，从<strong>第 2 行</strong>起填写数据。列顺序：
  <code>group_id</code>、<code>original_url</code>、<code>skip_url</code>、<code>status</code>（1 启用 / 2 停用）。
  无需填写 admin_id，系统自动使用当前登录账号。
</p>
<p><a href="{$templateUrl}" class="btn btn-sm btn-outline-secondary" target="_blank">下载导入模板</a></p>
HTML
        );

        $this->file('file', '上传 Excel')->rules('required', ['required' => '文件不能为空'])->move('admin/upload/');
    }
}
