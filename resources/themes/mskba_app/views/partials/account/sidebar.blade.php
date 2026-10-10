@php
    $accountItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('account');
    $hasActiveAccountGroup = collect($accountItems)->contains(
        fn (array $item): bool => ($item['active'] ?? false)
            && (! empty($item['children']) || ($item['openMobileOnActive'] ?? false))
    );
@endphp

<div class="panel app-account-nav app-account-nav--desktop">
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</div>

<details class="panel app-account-nav app-account-nav--mobile" @if ($hasActiveAccountGroup) open @endif>
    <summary class="app-account-nav__mobile-trigger">
        <span>Разделы аккаунта</span>
        <svg aria-hidden="true"><use href="#chevron-down"/></svg>
    </summary>
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</details>
