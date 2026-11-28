<?php

namespace App\Admin\Forms;

use App\Models\RediLagranSetting;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;
use Illuminate\Validation\ValidationException;

class LagranSettings extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-lagran.messages.forbidden'));
        }

        try {
            RediLagranSetting::store($input);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first();

            return $this->response()->error($message ?: __('redi-lagran.messages.invalid'));
        }

        return $this->response()->success(__('redi-lagran.messages.saved'))->refresh();
    }

    public function form()
    {
        $this->html('<div class="alert alert-info">'.e(__('redi-lagran.help.intro')).'</div>')->plain();

        $this->switch('enabled', __('redi-lagran.fields.enabled'))
            ->help(__('redi-lagran.help.enabled'));
        $this->switch('syn_ack', __('redi-lagran.fields.syn_ack'))
            ->help(__('redi-lagran.help.syn_ack'));
        $this->switch('ack', __('redi-lagran.fields.ack'))
            ->help(__('redi-lagran.help.ack'));

        $this->divider(__('redi-lagran.sections.windows'));
        $this->number('window_80', __('redi-lagran.fields.window_80'))
            ->min(1)->max(65535)
            ->help(__('redi-lagran.help.window_80'));
        $this->number('window_443', __('redi-lagran.fields.window_443'))
            ->min(1)->max(65535)
            ->help(__('redi-lagran.help.window_443'));
        $this->number('split_number', __('redi-lagran.fields.split_number'))
            ->min(0)->max(1000000)
            ->help(__('redi-lagran.help.split_number'));
        $this->number('window_after_split', __('redi-lagran.fields.window_after_split'))
            ->min(1)->max(65535)
            ->help(__('redi-lagran.help.window_after_split'));

        $this->divider(__('redi-lagran.sections.confusion'));
        $this->number('confusion_times', __('redi-lagran.fields.confusion_times'))
            ->min(0)->max(64)
            ->help(__('redi-lagran.help.confusion_times'));
        $this->number('window_scale', __('redi-lagran.fields.window_scale'))
            ->min(0)->max(14)
            ->help(__('redi-lagran.help.window_scale'));
        $this->number('window_confusion_last', __('redi-lagran.fields.window_confusion_last'))
            ->min(1)->max(65535)
            ->help(__('redi-lagran.help.window_confusion_last'));
    }

    public function default()
    {
        return RediLagranSetting::current()->toFormValues();
    }
}
