<?php

use App\Support\PlatformBranding;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Navbar;
use Dcat\Admin\Grid;
use Dcat\Admin\Form;
use Dcat\Admin\Grid\Filter;
use Dcat\Admin\Show;

app('view')->prependNamespace('admin', resource_path('views/admin'));

Admin::booting(function () {
    PlatformBranding::applyAdminConfig();
});

Admin::navbar(function (Navbar $navbar) {
    $navbar->right(view('admin::partials.locale-switcher'));
});

/**
 * Dcat-admin - admin builder based on Laravel.
 * @author jqh <https://github.com/jqhph>
 *
 * Bootstraper for Admin.
 *
 * Here you can remove builtin form field:
 *
 * extend custom field:
 * Dcat\Admin\Form::extend('php', PHPEditor::class);
 * Dcat\Admin\Grid\Column::extend('php', PHPEditor::class);
 * Dcat\Admin\Grid\Filter::extend('php', PHPEditor::class);
 *
 * Or require js and css assets:
 * Admin::css('/packages/prettydocs/css/styles.css');
 * Admin::js('/packages/prettydocs/js/main.js');
 *
 */
