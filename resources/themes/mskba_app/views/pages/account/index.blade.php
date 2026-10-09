@extends('theme::layouts.account', ['title' => 'Личный кабинет'])

@section('account-content')
    <section class="app-account-overview" aria-labelledby="account-overview-title">
        <header class="app-account-overview__intro">
            <h1 id="account-overview-title">Личный кабинет</h1>
            <p>Привет, {{ app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user ?? auth()->user()) }}! Здесь вы можете управлять своим профилем, уведомлениями и настройками портала.</p>
        </header>
        @if (app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup(auth()->user()))
            <div class="notice app-account-overview__pending" role="status">
                <div>
                    <strong>Остался последний шаг регистрации</strong>
                    <p>Чтобы начать пользоваться функционалом портала, завершите регистрацию, а пока можете осмотреться.</p>
                    <a class="button primary" href="{{ route('account.privacy.distribution') }}" data-open-onboarding>Завершить регистрацию</a>
                </div>
            </div>
        @endif
    </section>
@endsection
