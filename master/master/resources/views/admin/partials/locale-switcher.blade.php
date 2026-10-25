@php
    $current = app()->getLocale();
    $switchUrl = admin_url('locale');
    $switchFailedMsg = __('redi-locale.switch_failed');
@endphp
<ul class="nav navbar-nav">
    <li class="dropdown dropdown-language nav-item">
        <a class="dropdown-toggle nav-link" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fa fa-language"></i>
            <span class="selected-language">{{ __('redi-locale.names.'.$current) }}</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdown-language">
            @foreach (App\Http\Middleware\SetAdminLocale::supported() as $code)
                <li>
                    <a href="javascript:void(0)" class="dropdown-item redi-admin-locale" data-locale="{{ $code }}">
                        {{ __('redi-locale.names.'.$code) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
</ul>
<script>
Dcat.ready(function () {
  $(document).off('click', '.redi-admin-locale').on('click', '.redi-admin-locale', function (e) {
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
