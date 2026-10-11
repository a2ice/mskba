@extends('theme::layouts.account', ['title' => 'Личный кабинет'])

@section('account-heading')
    <section class="app-account-overview" aria-labelledby="account-overview-title">
        <header class="app-account-overview__intro">
            <div class="app-account-overview__heading">
                <h1 id="account-overview-title">Личный кабинет</h1>
            </div>

        </header>
    </section>
@endsection

@push('account-intro')
    <section class="panel app-account-intro" aria-label="О разделе">
        <p>Привет, {{ app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user ?? auth()->user()) }}! Здесь ты можешь управлять своим профилем, сообщениями и настройками портала.</p>
    </section>
@endpush

@section('account-content')
    <section class="app-account-overview" aria-label="Обзор аккаунта">
        @if (auth()->user()->canonical()->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED)
            <section class="panel app-account-overview__status" aria-labelledby="app-account-status-heading">
                <div class="app-account-overview__status-top">
                    <h2 id="app-account-status-heading">Статус пользователя</h2>
                    <span class="app-account-overview__status-label">Не подтверждён</span>
                </div>
                <p>Подтверди аккаунт, чтобы получить доступ ко всем возможностям портала.</p>
                <a class="button primary app-account-overview__confirmation-action" href="{{ route('account.confirmation') }}">
                    <span class="app-account-overview__confirmation-dot" aria-hidden="true"></span>
                    Подтвердить аккаунт
                </a>
            </section>
        @endif
        @if (app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup(auth()->user()))
            <div class="notice app-account-overview__pending" role="status">
                <div>
                    <strong>Остался последний шаг регистрации</strong>
                    <p>Чтобы начать пользоваться возможностями портала, заверши регистрацию, а пока можешь осмотреться.</p>
                    <a class="button primary" href="{{ route('account.privacy.distribution') }}" data-open-onboarding>Завершить регистрацию</a>
                </div>
            </div>
        @endif
    </section>
@endsection
