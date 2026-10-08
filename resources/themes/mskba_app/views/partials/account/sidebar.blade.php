@php
    $accountItems = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('account');
@endphp

<div class="panel app-account-nav app-account-nav--desktop">
    <div class="app-account-nav__heading">
        <p class="eyebrow accent">MSKBA / АККАУНТ</p>
        <h2>Личный кабинет</h2>
    </div>
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</div>

<details class="panel app-account-nav app-account-nav--mobile">
    <summary class="app-account-nav__mobile-trigger">
        <span>Разделы аккаунта</span>
        <svg aria-hidden="true"><use href="#chevron-down"/></svg>
    </summary>
    <nav class="app-account-nav__links" aria-label="Разделы аккаунта">
        @include('theme::partials.account.nav-items')
    </nav>
</details>
