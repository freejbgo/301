<?php

namespace App\Admin\Controllers;

use App\Admin\Forms\PlatformSettings;
use App\Http\Controllers\Controller;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Card;

class PlatformSettingsController extends Controller
{
    public function index(Content $content)
    {
        if (! Admin::user() || ! Admin::user()->isAdministrator()) {
            abort(403, __('redi-platform-settings.messages.forbidden'));
        }

        $card = Card::make(__('redi-platform-settings.cards.branding'), new PlatformSettings());

        return $content
            ->title(__('redi-platform-settings.page.title'))
            ->description(__('redi-platform-settings.page.description'))
            ->body($card);
    }
}
