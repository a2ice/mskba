@extends('theme::layouts.account', ['title' => 'Личный кабинет'])

@section('account-heading')
    <section class="app-account-overview" aria-labelledby="account-overview-title">
        <header class="app-account-overview__intro">
            <div class="app-account-overview__heading">
                <h1 id="account-overview-title">Личный кабинет</h1>
                @if (auth()->user()->canonical()->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED)
                    <button type="button" class="app-account-overview__confirmation-badge"
                            data-account-confirmation-guide-open
                            aria-haspopup="dialog" aria-controls="account-confirmation-guide-dialog">
                        Не подтверждён
                    </button>
                @endif
            </div>
            <p>Привет, {{ app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user ?? auth()->user()) }}! Здесь ты можешь управлять своим профилем, уведомлениями и настройками портала.</p>
        </header>
    </section>
@endsection

@section('account-content')
    <section class="app-account-overview" aria-label="Обзор аккаунта">
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
