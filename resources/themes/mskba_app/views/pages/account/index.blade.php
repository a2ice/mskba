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
    @if (isset($error))
        <p class="notice" role="alert">{{ $error['message'] }}</p>
    @else
        <section class="app-account-overview" aria-label="Обзор аккаунта">
            <p class="app-dashboard-customize__status" data-dashboard-order-status role="status" aria-live="polite"></p>
            <div class="app-dashboard-grid" data-account-dashboard data-refresh-url="{{ route('account.dashboard.widgets') }}"
                 data-order-url="{{ route('account.dashboard.widgets.order') }}"
                 data-visibility-url="{{ route('account.settings.dashboard.update') }}">
                @include('theme::pages.account.partials.dashboard-widget-list')
            </div>
            <dialog class="mskba-modal app-dashboard-picker" data-dashboard-picker
                    aria-labelledby="app-dashboard-picker-title">
                <header class="mskba-modal__header app-dashboard-picker__header">
                    <h2 id="app-dashboard-picker-title">Добавить виджет</h2>
                    <button type="button" class="icon-button app-dashboard-picker__close"
                            data-dashboard-picker-close aria-label="Закрыть окно">
                        <svg aria-hidden="true"><use href="#close"/></svg>
                    </button>
                </header>
                <div class="mskba-modal__body mskba-scroll app-dashboard-picker__body">
                    <p>Выбери доступный виджет, чтобы добавить его в личный кабинет.</p>
                    <div class="app-dashboard-picker__list" data-dashboard-picker-list></div>
                    <p class="app-dashboard-picker__status" data-dashboard-picker-status role="status" aria-live="polite"></p>
                </div>
            </dialog>
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
