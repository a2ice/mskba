@extends('theme::layouts.account', ['title' => 'Настройка приватности'])

@section('account-content')
    @include('theme::pages.account.partials.privacy-onboarding-content')
    <div class="toast privacy-onboarding__toast" role="status" aria-live="polite"
         data-privacy-reminder-toast hidden>
        <svg aria-hidden="true"><use href="#bell"/></svg>
        <div><strong>Остался последний шаг регистрации</strong>
            <p>Выбери, что будет видно другим участникам, и заверши настройку.</p></div>
        <button class="icon-button" type="button" aria-label="Закрыть уведомление"
                data-privacy-toast-close><svg aria-hidden="true"><use href="#close"/></svg></button>
    </div>
@endsection
