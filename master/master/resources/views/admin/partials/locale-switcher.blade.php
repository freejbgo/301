@php
    $current = app()->getLocale();
@endphp
<ul class="nav navbar-nav">
    <li class="dropdown dropdown-language nav-item">
        <a class="dropdown-toggle nav-link" href="javascript:void(0)" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="feather icon-globe"></i>
            <span class="selected-language">{{ __('redi-locale.names.'.$current) }}</span>
        </a>
        <div class="dropdown-menu dropdown-menu-right">
            @foreach (App\Http\Middleware\SetAdminLocale::supported() as $code)
                <a href="javascript:void(0)" class="dropdown-item redi-admin-locale {{ $code === $current ? 'active' : '' }}" data-locale="{{ $code }}">
                    <span>{{ __('redi-locale.names.'.$code) }}</span>
                    @if ($code === $current)
                        <i class="feather icon-check"></i>
                    @endif
                </a>
            @endforeach
        </div>
    </li>
</ul>
