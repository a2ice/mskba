@extends('theme::layouts.account', ['title' => 'Личный кабинет'])
@section('account-heading')
    <section class="app-account-overview" aria-labelledby="account-overview-title">
        <header class="app-account-overview__intro">
            <div class="app-account-overview__heading"><h1 id="account-overview-title">Личный кабинет</h1></div>
        </header>
    </section>
@endsection
@push('account-intro')
    <section class="panel app-account-intro" aria-label="О разделе">
        <p>Привет, {{ app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user ?? auth()->user()) }}! Здесь ты можешь управлять своим профилем, сообщениями и настройками портала.</p>
        @if (auth()->user()?->canonical()->isAdmin())
            <p class="app-dashboard-admin__shortcut"><a href="{{ url('/backoffice/account-dashboard-widgets') }}">Настроить виджеты</a></p>
        @endif
    </section>
@endpush
@section('account-content')
@if(($user ?? auth()->user())?->canonical()->profile)
    @include('components.entity-banner-editor', ['model' => ($user ?? auth()->user())->canonical()->profile, 'context' => 'account'])
@endif

    @if (isset($error))
        <p class="notice" role="alert">{{ $error['message'] }}</p>
    @else
        <section class="app-account-overview" aria-label="Обзор аккаунта">
            <div class="app-dashboard-grid" data-account-dashboard data-refresh-url="{{ route('account.dashboard.widgets') }}">
                @include('theme::pages.account.partials.dashboard-widget-list')
            </div>
            @if (app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup(auth()->user()))
                <div class="notice app-account-overview__pending" role="status">
                    <div><strong>Остался последний шаг регистрации</strong>
                        <p>Чтобы начать пользоваться возможностями портала, заверши регистрацию, а пока можешь осмотреться.</p>
                        <a class="button primary" href="{{ route('account.privacy.distribution') }}" data-open-onboarding>Завершить регистрацию</a>
                    </div>
                </div>
            @endif
        </section>
    @endif
@endsection
