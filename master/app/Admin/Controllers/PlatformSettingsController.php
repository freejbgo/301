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
            abort(403, '仅超级管理员可修改系统外观');
        }

        $card = Card::make('全平台外观（Logo / 标题）', new PlatformSettings());

        return $content
            ->title('系统外观')
            ->description('全平台共用一套品牌设置')
            ->body($card);
    }
}
