@extends('theme::layouts.app', ['title' => 'Завершение регистрации через VK ID'])

@section('content')
<section class="section first-screen px-1">
    <div class="inner" style="max-width:640px">
        <div class="section-heading mb-4">
            <h1>Завершение регистрации через VK ID</h1>
        </div>

        <div class="event-card">
            <p>
                VK ID подтвердил вашу учётную запись. Чтобы создать новый аккаунт MSKBA,
                необходимо отдельно подтвердить согласие на обработку персональных данных.
            </p>

            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('auth.vk.consent.store') }}">
                @csrf

                <label class="privacy-consent mb-2">
                    <input
                        class="privacy-consent__input"
                        type="checkbox"
                        name="privacy_consent"
                        value="1"
                        required
                        @checked(old('privacy_consent'))
                    >
                    <span class="privacy-consent__control" aria-hidden="true"></span>
                    <span class="privacy-consent__text">
                        Я даю <a href="{{ route('personal-data.consent') }}" target="_blank" rel="noopener">согласие на обработку персональных данных</a>.
                    </span>
                </label>

                <p class="form-text mb-4">
                    <a href="{{ route('privacy.policy') }}" target="_blank" rel="noopener">Политика обработки персональных данных</a>
                    описывает порядок и условия обработки и не является согласием.
                </p>

                <button class="btn btn--primary" type="submit">Создать аккаунт и войти</button>
            </form>
        </div>
    </div>
</section>
@endsection
