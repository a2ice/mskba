@php
    $accountItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('account');
    // Indicate the current destination without expanding the mobile navigation.
    // AccountMenu marks both direct links and parent groups as active.
    $hasActiveAccountItem = collect($accountItems)->contains(
        fn (array $item): bool => ($item['visible'] ?? true)
            && (($item['active'] ?? false) || collect($item['children'] ?? [])->contains(
                fn (array $child): bool => ($child['visible'] ?? true) && ($child['active'] ?? false)
            ))
    );
@endphp

<div class="panel app-account-nav app-account-nav--desktop">
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</div>

<details class="panel app-account-nav app-account-nav--mobile">
    <summary class="app-account-nav__mobile-trigger" @if ($hasActiveAccountItem) aria-label="Разделы аккаунта, текущий раздел выбран" @endif>
        <span class="app-account-nav__mobile-label">
            Разделы аккаунта
            @if ($hasActiveAccountItem)
                <span class="app-account-nav__current-dot" aria-hidden="true"></span>
            @endif
        </span>
        <svg aria-hidden="true"><use href="#chevron-down"/></svg>
    </summary>
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</details>
