@php
    $current = app()->getLocale();
    $labels = [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁體中文',
        'en' => 'English',
    ];
    $switchUrl = admin_url('locale');
    $switchFailedMsg = __('redi-locale.switch_failed');
@endphp
<div class="redi-login-locale text-center mt-3">
    <span class="text-muted small">{{ __('redi-locale.login_choose') }}</span>
    @foreach (App\Http\Middleware\SetAdminLocale::supported() as $code)
        @if ($code === $current)
            <span class="badge badge-primary mx-1">{{ $labels[$code] ?? $code }}</span>
        @else
            <a href="javascript:void(0)" class="redi-admin-locale badge badge-light mx-1" data-locale="{{ $code }}">{{ $labels[$code] ?? $code }}</a>
        @endif
    @endforeach
</div>
<script>
Dcat.ready(function () {
  $(document).off('click.rediLoginLocale', '.redi-login-locale .redi-admin-locale')
    .on('click.rediLoginLocale', '.redi-login-locale .redi-admin-locale', function (e) {
    e.preventDefault();
    var locale = $(this).data('locale');
    Dcat.loading();
    $.ajax({
      method: 'POST',
      url: @json($switchUrl),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      data: { locale: locale, _token: Dcat.token }
    }).done(function () {
      window.location.reload();
    }).fail(function () {
      Dcat.loading(false);
      Dcat.error(@json($switchFailedMsg));
    });
  });
});
</script>
