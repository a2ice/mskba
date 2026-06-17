@php
    $accountUrl = auth()->check()
        ? (\Illuminate\Support\Facades\Route::has('account') ? route('account') : '#')
        : (\Illuminate\Support\Facades\Route::has('login') ? route('login') : '#');
@endphp

<div class="mskba-header__actions">
    <a href="{{ $accountUrl }}" class="btn btn--ghost mskba-account-link">
        <i class="ti ti-user" aria-hidden="true"></i>
        Личный кабинет
    </a>
</div>
