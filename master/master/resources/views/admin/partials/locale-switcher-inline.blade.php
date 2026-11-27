@php
    $current = app()->getLocale();
@endphp
<div class="redi-login-locale">
    <label for="redi-login-locale">{{ __('redi-locale.login_choose') }}</label>
    <select id="redi-login-locale">
        @foreach (App\Http\Middleware\SetAdminLocale::supported() as $code)
            <option value="{{ $code }}" {{ $code === $current ? 'selected' : '' }}>{{ __('redi-locale.names.'.$code) }}</option>
        @endforeach
    </select>
</div>
