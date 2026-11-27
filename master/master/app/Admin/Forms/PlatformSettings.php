<?php

namespace App\Admin\Forms;

use App\Http\Middleware\SetAdminLocale;
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
        // Dcat drops every field that sits outside a tab. This view prints those fields under the tabs.
        $this->view = 'admin::widgets.platform-settings-form';

        $locales = SetAdminLocale::supported();
        $tab = null;
        foreach ($locales as $index => $locale) {
            $label = __('redi-platform-settings.tabs.'.$locale);
            $builder = function (Form $form) use ($locale) {
                $form->text("locales.{$locale}.system_name", __('redi-platform-settings.fields.system_name'))
                    ->help(__('redi-platform-settings.help.system_name'));
                $form->text("locales.{$locale}.page_title", __('redi-platform-settings.fields.page_title'))
                    ->help(__('redi-platform-settings.help.page_title'));
                $form->text("locales.{$locale}.login_title", __('redi-platform-settings.fields.login_title'))
                    ->help(__('redi-platform-settings.help.login_title'));
            };

            if ($index === 0) {
                $tab = $this->tab($label, $builder);
            } else {
                $tab = $tab->tab($label, $builder);
            }
        }

        $this->divider(__('redi-platform-settings.sections.shared_assets'));

        $this->image('logo', __('redi-platform-settings.fields.logo'))
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->removable()
            ->accept('png,jpg,jpeg,gif,webp', 'image/png,image/jpeg,image/gif,image/webp')
            ->help(__('redi-platform-settings.help.logo'));

        $this->image('logo_mini', __('redi-platform-settings.fields.logo_mini'))
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->removable()
            ->accept('png,jpg,jpeg,gif,webp', 'image/png,image/jpeg,image/gif,image/webp')
            ->help(__('redi-platform-settings.help.logo_mini'));

        $this->file('favicon', __('redi-platform-settings.fields.favicon'))
            ->disk('public')
            ->uniqueName()
            ->move('branding')
            ->autoUpload()
            ->removable()
            ->accept('png,jpg,jpeg,gif,webp,ico', 'image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon')
            ->help(__('redi-platform-settings.help.favicon_shared'));
    }

    public function default()
    {
        return PlatformBranding::formDefaults();
    }
}
