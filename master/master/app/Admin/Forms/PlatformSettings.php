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
            return $this->response()->error(__('redi-platform-settings.messages.forbidden'));
        }

        PlatformBranding::save($input);

        return $this->response()->success(__('redi-platform-settings.messages.saved'))->refresh();
    }

    public function form()
    {
        $this->text('system_name', __('redi-platform-settings.fields.system_name'))
            ->required()
            ->help(__('redi-platform-settings.help.system_name'));
        $this->text('page_title', __('redi-platform-settings.fields.page_title'))
            ->help(__('redi-platform-settings.help.page_title'));
        $this->text('login_title', __('redi-platform-settings.fields.login_title'))
            ->help(__('redi-platform-settings.help.login_title'));

        $this->image('logo', __('redi-platform-settings.fields.logo'))
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->help(__('redi-platform-settings.help.logo'));

        $this->image('logo_mini', __('redi-platform-settings.fields.logo_mini'))
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->help(__('redi-platform-settings.help.logo_mini'));

        $this->image('favicon', __('redi-platform-settings.fields.favicon'))
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
