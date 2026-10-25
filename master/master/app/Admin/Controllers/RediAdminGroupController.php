<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\RediAdminGroup;
use App\Support\AdminTenantContext;
use Illuminate\Support\Facades\DB;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Widgets\Radio;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use App\Admin\Forms\UrlSetByGroup;
use Dcat\Admin\Widgets\Modal;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Validation\ValidationException;

class RediAdminGroupController extends AdminController
{
    protected $translation = 'redi-admin-group';

    public function title()
    {
        return admin_trans_label('title');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new RediAdminGroup(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('name')->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 120));
            });
            if (AdminTenantContext::isPlatformAdministrator()) {
                $grid->column('admin_name', admin_trans_field('admin_name'))->display(function ($value) {
                    return e(OpsDisplaySanitizer::field($value, '—', 120));
                });
            }
            $grid->column('status', admin_trans_field('status'))->using(admin_trans('redi-admin-group.options.status'));
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();

            $grid->column('avgMonthCost')->display(admin_trans_label('batch_redirect'))->modal(function (Grid\Displayers\Modal $modal) {
                $modal->title(admin_trans_label('batch_modal'));
                // 自定义图标
                $modal->icon('feather icon-edit');
                // 传递当前行字段值
                return UrlSetByGroup::make()->payload(['id' => $this->id]);
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->panel();

                $filter->like('name')->width(3);
                $filter->equal('status', admin_trans_field('status'))->select(admin_trans('redi-admin-group.options.status'))->width(3);
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
            $form->radio('status', admin_trans_field('status'))->options(admin_trans('redi-admin-group.options.status'))->default(1);
            //$form->text('status');

            if ($form->isCreating()) {
                if (AdminTenantContext::isPlatformAdministrator()) {
                    $owners = AdminTenantContext::tenantOwnerSelectOptions();
                    if ($owners === []) {
                        $form->html('<p class="text-danger">'.e(admin_trans_label('no_tenant')).'</p>');
                    }
                    $form->select('owner_admin_id', admin_trans_field('owner_admin_id'))
                        ->options($owners)
                        ->required()
                        ->help(admin_trans('redi-admin-group.help.owner_admin_id'));
                } else {
                    $form->hidden('admin_id')->value(Admin::user()->id);
                    $form->hidden('admin_name')->value(Admin::user()->username);
                }
            }

            $form->deleting(function (Form $form) {
                $group = \App\Models\RediAdminGroup::query()->find($form->getKey());
                if (! $group) {
                    return;
                }
                $count = \App\Models\RediAdminDomain::query()
                    ->where('group_id', $group->id)
                    ->where('admin_id', $group->admin_id)
                    ->count();
                if ($count > 0) {
                    throw ValidationException::withMessages([
                        'name' => admin_trans('redi-admin-group.validation.delete_has_domains', ['count' => $count]),
                    ]);
                }
            });

            $form->saving(function (Form $form) {
                if ($form->isEditing()) {
                    $existing = \App\Models\RediAdminGroup::query()->find($form->getKey());
                    if ($existing) {
                        $form->input('admin_id', $existing->admin_id);
                        $form->input('admin_name', $existing->admin_name);
                        $form->model()->forceFill([
                            'admin_id' => (int) $existing->admin_id,
                            'admin_name' => (string) $existing->admin_name,
                        ]);
                    }
                } elseif ($form->isCreating()) {
                    if (AdminTenantContext::isPlatformAdministrator()) {
                        $ownerId = (int) request()->input('owner_admin_id');
                        $usersTable = (string) config('admin.database.users_table', 'admin_users');
                        $owner = DB::table($usersTable)->where('id', $ownerId)->first();
                        if (! $owner) {
                            throw ValidationException::withMessages([
                                'owner_admin_id' => admin_trans('redi-admin-group.validation.owner_invalid'),
                            ]);
                        }
                        $allowed = AdminTenantContext::tenantOwnerSelectOptions();
                        if (! isset($allowed[$ownerId])) {
                            throw ValidationException::withMessages([
                                'owner_admin_id' => admin_trans('redi-admin-group.validation.owner_not_tenant'),
                            ]);
                        }
                        $form->model()->forceFill([
                            'admin_id' => $ownerId,
                            'admin_name' => (string) $owner->username,
                        ]);
                    } else {
                        $form->model()->forceFill([
                            'admin_id' => (int) Admin::user()->id,
                            'admin_name' => (string) Admin::user()->username,
                        ]);
                    }
                }
            });

            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
