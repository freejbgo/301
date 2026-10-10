<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\RediAdminDomain;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Widgets\Dropdown;
use Illuminate\Support\Facades\DB;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use App\Admin\Actions\Modal\BatchDomainModal;
use App\Models\RediAdminGroup;
use App\Services\DomainRecordNormalizer;
use Illuminate\Validation\ValidationException;


class RediAdminDomainController extends AdminController
{
    public function title()
    {
        return '域名管理';
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new RediAdminDomain(), function (Grid $grid) {
            
            $grid->tools(function (Grid\Tools $tools) {
                $tools->append(new BatchDomainModal());
            });
            
            $grid->model()->where('admin_id', '=', Admin::user()->id);
            $grid->model()->with(['group']);

            $grid->column('id')->sortable();
            $grid->column('group_id', '分组名称')->display(function () {
                return $this->group?->name ?? '（分组不存在）';
            });
            $grid->column('original_url', '原始域名');
            $grid->column('skip_url', '跳转地址');
            $grid->column('skip_num', '跳转次数');
            $grid->column('status', '状态')->using([1 => "启用", 2 => "停用"]);
            $grid->column('admin_name', '添加人');
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();

            $grid->filter(function (Grid\Filter $filter) {
                $filter->panel();

                //获取分类列表
                $groupList = [];
                $groupRet = DB::table("redi_admin_group")->where('admin_id', Admin::user()->id)->get(['id', 'name']);
                foreach($groupRet as $val){
                    $groupList[$val->id] = $val->name;
                }
                $filter->equal('group_id', '分组')->select($groupList)->width(3);

                $filter->like('original_url', '原始域名')->width(4);
                $filter->like('skip_url', '跳转地址')->width(4);
                $filter->equal('status', '状态')->select([1 => '启用', 2 => '停用'])->width(3);

            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new RediAdminDomain(), function (Show $show) {
            $show->field('id');
            $show->field('group_id');
            $show->field('original_url');
            $show->field('skip_url');
            $show->field('status');
            $show->field('admin_id');
            $show->field('admin_name');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new RediAdminDomain(), function (Form $form) {
            $groupList = [];
            $groupRet = DB::table("redi_admin_group")->where('admin_id', Admin::user()->id)->where('status', 1)->get(['id', 'name']);
            foreach($groupRet as $val){
                $groupList[$val->id] = $val->name;
            }

            $form->display('id');
            $form->select('group_id', '分组')->options($groupList);
            $form->text('original_url', '原始域名');
            $form->text('skip_url', '跳转链接');
            $form->radio('status', '状态')->options([1=>'启用', 2=>'停用'])->default(1);
            if ($form->isCreating()) {
                $form->hidden('admin_id')->value(Admin::user()->id);
                $form->hidden('admin_name')->value(Admin::user()->username);
            }

            $form->display('created_at');
            $form->display('updated_at');

            $form->saving(function (Form $form) {
                $request = request()->all();
                $adminId = (int) Admin::user()->id;

                $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($request['original_url'] ?? '');
                $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($request['skip_url'] ?? '');
                $groupId = (int) ($request['group_id'] ?? 0);

                if ($originalUrl === '' || $skipUrl === '') {
                    throw ValidationException::withMessages([
                        'original_url' => '原始域名与跳转链接不能为空',
                    ]);
                }

                $groupOk = RediAdminGroup::query()
                    ->where('admin_id', $adminId)
                    ->where('id', $groupId)
                    ->exists();

                if (! $groupOk) {
                    throw ValidationException::withMessages([
                        'group_id' => '分组无效或不属于当前账号',
                    ]);
                }

                if ($form->isCreating()) {
                    $duplicate = \App\Models\RediAdminDomain::query()
                        ->where('admin_id', $adminId)
                        ->where('original_url', $originalUrl)
                        ->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'original_url' => '该原始域名已存在',
                        ]);
                    }
                    $form->input('admin_id', $adminId);
                    $form->input('admin_name', Admin::user()->username);
                }

                $form->input('original_url', $originalUrl);
                $form->input('skip_url', $skipUrl);
            });
        });
    }
}
