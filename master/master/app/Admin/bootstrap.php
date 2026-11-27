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

    Admin::style(<<<'CSS'
#login-form::after { content: ""; display: table; clear: both; }
.redi-login-locale {
    clear: both;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #e6e9f0;
}
.redi-login-locale label {
    margin: 0;
    color: #8b93a7;
    font-size: 12px;
    font-weight: 400;
    white-space: nowrap;
}
.redi-login-locale select,
.redi-login-locale select option {
    color: #2a2e30;
    background-color: #fff;
}
.redi-login-locale select {
    flex: 1;
    min-width: 0;
    height: 34px;
    margin: 0;
    padding: 0 28px 0 10px;
    border: 1px solid #d5dbe7;
    border-radius: 6px;
    background-color: #fff;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M1.4.6 6 5.2 10.6.6 12 2 6 8 0 2z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    font-size: 13px;
    line-height: 34px;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}
.header-navbar .navbar-right > ul.navbar-nav { align-items: center; margin-bottom: 0; }
.header-navbar .navbar-right .dropdown-language > a.nav-link,
.header-navbar .navbar-right .dropdown-language > a.nav-link .selected-language {
    color: #2a2e30;
}
.header-navbar .navbar-right .dropdown-language > a.nav-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 40px;
    padding: 0 12px;
    line-height: 1;
}
.header-navbar .navbar-right .dropdown-language > a.nav-link .feather { font-size: 16px; color: #2a2e30; }
.header-navbar .navbar-right .dropdown-language .dropdown-menu {
    min-width: 148px;
    padding: 6px 0;
    margin-top: 8px;
    background: #fff;
}
.header-navbar .navbar-right .dropdown-language .dropdown-item,
.header-navbar .navbar-right .dropdown-language .dropdown-item span {
    color: #2a2e30;
}
.header-navbar .navbar-right .dropdown-language .dropdown-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 14px;
    background: #fff;
}
.header-navbar .navbar-right .dropdown-language .dropdown-item:hover,
.header-navbar .navbar-right .dropdown-language .dropdown-item:hover span {
    color: #2a2e30;
    background: #f4f7fa;
}
.header-navbar .navbar-right .dropdown-language .dropdown-item.active,
.header-navbar .navbar-right .dropdown-language .dropdown-item.active span {
    background: #fff;
    color: #586cb1;
    font-weight: 600;
}
.header-navbar .navbar-right .dropdown-language .dropdown-item .feather { font-size: 14px; }
CSS
    );

    $switchUrl = json_encode(admin_url('locale'));
    $switchFailed = json_encode(__('redi-locale.switch_failed'));
    Admin::script(<<<JS
Dcat.ready(function () {
  function rediSwitchLocale(locale) {
    if (!locale) { return; }
    Dcat.loading();
    $.ajax({
      method: 'POST',
      url: {$switchUrl},
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      data: { locale: locale, _token: Dcat.token }
    }).done(function () {
      window.location.reload();
    }).fail(function () {
      Dcat.loading(false);
      var \$sel = $('#redi-login-locale');
      if (\$sel.length && \$sel.data('prev')) { \$sel.val(\$sel.data('prev')); }
      Dcat.error({$switchFailed});
    });
  }
  $(document).off('focus.rediLocale', '#redi-login-locale')
    .on('focus.rediLocale', '#redi-login-locale', function () { $(this).data('prev', this.value); });
  $(document).off('change.rediLocale', '#redi-login-locale')
    .on('change.rediLocale', '#redi-login-locale', function () { rediSwitchLocale(this.value); });
  $(document).off('click.rediLocale', '.redi-admin-locale')
    .on('click.rediLocale', '.redi-admin-locale', function (e) {
      e.preventDefault();
      if ($(this).hasClass('active')) { return; }
      rediSwitchLocale($(this).data('locale'));
    });
});
JS
    );

    $prefix = trim((string) config('admin.route.prefix', 'admin'), '/');
    $loginPath = ($prefix !== '' ? $prefix.'/' : '').'auth/login';
    if (request()->is($loginPath)) {
        $inline = view('admin::partials.locale-switcher-inline')->render();
        Admin::script(
            'Dcat.ready(function () {'
            .'var $card = $(".login-card-body").first();'
            .'if (!$card.length) { $card = $(".login-box .card-body").first(); }'
            .'if ($card.length && !$card.find("#redi-login-locale").length) { $card.append('.json_encode($inline).'); }'
            .'});'
        );
    }
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
