<?php

namespace App\Admin\Forms;

use App\Support\PlatformBranding;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;

class PlatformSettings extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error('仅超级管理员可修改系统外观');
        }

        PlatformBranding::save($input);

        return $this->response()->success('系统外观已保存')->refresh();
    }

    public function form()
    {
        $this->text('system_name', '系统名称')
            ->required()
            ->help('侧边栏 Logo 旁显示的名称');
        $this->text('page_title', '网页标题')
            ->help('浏览器标签页标题，留空则与系统名称相同');
        $this->text('login_title', '登录框标题')
            ->help('登录页主标题，留空则与系统名称相同');

        $this->image('logo', 'Logo（展开侧栏）')
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->help('建议 PNG，高度约 35px');

        $this->image('logo_mini', 'Logo（收起侧栏）')
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->help('留空则使用上方 Logo');

        $this->image('favicon', 'Favicon')
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload();
    }

    public function default()
    {
        return PlatformBranding::get();
    }
}
