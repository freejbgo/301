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
use App\Models\RediAdminDomain as RediAdminGroup;
use Dcat\Admin\Layout\Content;

use App\Admin\Actions\Modal\memberModal;


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
            
            $grid->tools(function  (Grid\Tools  $tools)  { 
                //Excel导入
                $tools->append(new  memberModal());  
            });
            
            $grid->model()->where('admin_id', '=', Admin::user()->id);
            $grid->model()->with(['group']);

            $grid->column('id')->sortable();
            $grid->column('group_id', '分组名称')->display(function ($groupId){
                return ($this->group)->name;
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
            $form->hidden('admin_id')->value(Admin::user()->id);
            $form->hidden('admin_name')->value(Admin::user()->username);

            $form->display('created_at');
            $form->display('updated_at');

            $form->saving(function (Form $form) {
                $request = request()->all();
                $form->input('original_url', preg_replace('#^https?://#', '', rtrim($request['original_url'],'/')));

                if ( count(explode('://', $request['skip_url']))>1 ){
                    $skip_url = $request['skip_url'];
                }else{
                    $skip_url = 'http://'.$request['skip_url'];
                }

                $form->input('skip_url', $skip_url);
            });
        });
    }
}
