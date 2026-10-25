@php
    $current = app()->getLocale();
    $labels = [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁體中文',
        'en' => 'English',
    ];
    $switchUrl = admin_url('locale');
@endphp
<ul class="nav navbar-nav">
    <li class="dropdown dropdown-language nav-item">
        <a class="dropdown-toggle nav-link" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fa fa-language"></i>
            <span class="selected-language">{{ $labels[$current] ?? $current }}</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdown-language">
            @foreach (App\Http\Middleware\SetAdminLocale::supported() as $code)
                <li>
                    <a href="javascript:void(0)" class="dropdown-item redi-admin-locale" data-locale="{{ $code }}">
                        {{ $labels[$code] ?? $code }}
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
      data: { locale: locale, _token: Dcat.token }
    }).done(function () {
      window.location.reload();
    }).fail(function () {
      Dcat.loading(false);
      Dcat.error('Language switch failed');
    });
  });
});
</script>
