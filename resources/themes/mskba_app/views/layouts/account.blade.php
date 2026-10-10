@extends('theme::layouts.app', ['title' => $title ?? 'Аккаунт'])

@section('content')
    <section class="app-account-section" aria-label="Личный кабинет">
        <div class="container app-account-section__inner">
            @if ($isFirstSetup ?? false)
                <div class="app-account-onboarding-content" data-privacy-onboarding-shell>
                    @yield('account-content')
                </div>
            @else
                @hasSection('account-heading')
                    <div class="app-account-section__heading">
                        @yield('account-heading')
                    </div>
                @endif
                <div class="app-account-layout">
                    <aside class="app-account-layout__aside" aria-label="Навигация аккаунта">
                        @include('theme::partials.account.sidebar')
                    </aside>
                    <div class="app-account-layout__content">
                        @yield('account-content')
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
