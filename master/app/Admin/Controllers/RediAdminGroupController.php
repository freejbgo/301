<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\RediAdminGroup;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Widgets\Radio;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use App\Admin\Forms\UrlSetByGroup;
use Dcat\Admin\Widgets\Modal;

class RediAdminGroupController extends AdminController
{
    public function title()
    {
        return "分组管理";
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new RediAdminGroup(), function (Grid $grid) {
            $grid->model()->where('admin_id', '=', Admin::user()->id);
            $grid->column('id')->sortable();
            $grid->column('name');
            $grid->column('status', '状态')->using([1 => "启用", 2 => "停用"]);
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();

            $grid->column('avgMonthCost')->display('批量修改跳转')->modal(function (Grid\Displayers\Modal $modal) {
                // 标题
                $modal->title('批量修改跳转');
                // 自定义图标
                $modal->icon('feather icon-edit');
                // 传递当前行字段值
                return UrlSetByGroup::make()->payload(['id' => $this->id]);
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->panel();

                $filter->like('name')->width(3);
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
        return Show::make($id, new RediAdminGroup(), function (Show $show) {
            $show->field('id');
            $show->field('name');
            $show->field('status');
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
        return Form::make(new RediAdminGroup(), function (Form $form) {
            $form->display('id');
            $form->text('name');
            $form->radio('status', '状态')->options([1=>'启用', 2=>'停用'])->default(1);
            //$form->text('status');

            if ($form->isCreating()) {
                $form->hidden('admin_id')->value(Admin::user()->id);
                $form->hidden('admin_name')->value(Admin::user()->username);
            }

            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
