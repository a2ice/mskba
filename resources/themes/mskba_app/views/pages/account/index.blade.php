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
        @php($accountStatus = auth()->user()->canonical()->status)
        <section class="panel app-account-overview__status" aria-labelledby="app-account-status-heading">
            <div class="app-account-overview__status-top">
                <h2 id="app-account-status-heading">Статус пользователя</h2>
                <span class="app-account-overview__status-label {{ $accountStatus === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED ? 'app-account-overview__status-label--confirmed' : '' }}">{{ $accountStatus->label() }}</span>
            </div>
            @if ($accountStatus === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED)
                <p>Подтверди аккаунт, чтобы получить доступ ко всем возможностям портала.</p>
                <a class="button secondary app-account-overview__confirmation-action" href="{{ route('account.confirmation') }}">
                    <span class="app-account-overview__confirmation-dot" aria-hidden="true"></span>
                    Подтвердить аккаунт
                </a>
            @elseif ($accountStatus === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED)
                <p>Аккаунт подтверждён. Теперь доступны возможности портала, для которых требуется подтверждение аккаунта.</p>
            @else
                <p>Аккаунт заблокирован. Если возникли вопросы, обратись в поддержку.</p>
            @endif
        </section>
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
