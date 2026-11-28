<?php

namespace App\Admin\Controllers;

use App\Admin\Forms\LagranSettings;
use App\Http\Controllers\Controller;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Card;

class LagranSettingsController extends Controller
{
    public function index(Content $content)
    {
        if (! Admin::user() || ! Admin::user()->isAdministrator()) {
            abort(403, __('redi-lagran.messages.forbidden'));
        }

        $card = Card::make(__('redi-lagran.cards.settings'), new LagranSettings());

        return $content
            ->title(__('redi-lagran.page.title'))
            ->description(__('redi-lagran.page.description'))
            ->body($card);
    }
}
