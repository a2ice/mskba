@extends('theme::layouts.app', ['title' => 'Регистрация на турнир · '.$tournament->title])

@section('content')
<section class="section first-screen px-1 tournament-check-in" data-tournament-check-in @guest data-username-url="{{ route('tournaments.on-site.username', $tournament->routeIdentifier()) }}" @endguest>
    <div class="inner" style="max-width:760px">
        <div class="section-heading"><h1>Регистрация на турнир</h1><p>{{ $tournament->title }}</p></div>
        <div class="event-card">
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @if(! $available)
                <div class="alert alert-info mb-0"><strong>Регистрация на месте закрыта.</strong><br>Обратитесь к организатору турнира.</div>
            @elseif($hasActiveAdmission)
                <div class="alert alert-success mb-0">Ваша заявка уже отправлена или принята.</div>
            @else
                <p>Выберите, в качестве кого хотите участвовать. Ответственный за турнир рассмотрит заявку и при необходимости добавит вас в команду.</p>
                <form method="POST" action="{{ route('tournaments.on-site.store', $tournament->routeIdentifier()) }}">
                    @csrf
                    @guest
                        <div class="mb-4">
                            <label class="form-label" for="onSiteUsername">Придумайте логин</label>
                            <input id="onSiteUsername" class="form-control @error('username') is-invalid @enderror" name="username" value="{{ old('username') }}" minlength="3" maxlength="32" autocomplete="username" required data-check-in-username>
                            <p class="form-text" data-check-in-username-message>Латинские буквы, цифры, точка, дефис и подчёркивание.</p>
                            @error('username')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    @else
                        <div class="alert alert-info">Вы вошли как <strong>{{ auth()->user()->username }}</strong>.</div>
                    @endguest
                    <fieldset class="mb-4"><legend class="form-label">В качестве кого?</legend>
                        @foreach($roles as $role)
                            @include('theme::partials.forms.toggle', [
                                'id' => 'on-site-role-'.$role->value,
                                'name' => 'roles[]',
                                'value' => $role->value,
                                'title' => $role->label(),
                                'checked' => in_array($role->value, old('roles', []), true),
                                'includeHiddenInput' => false,
                                'wrapperClass' => 'mb-2',
                            ])
                        @endforeach
                        @error('roles')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </fieldset>
                    @guest
                        <label class="privacy-consent mb-4">
                            <input class="privacy-consent__input" type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))>
                            <span class="privacy-consent__control" aria-hidden="true"></span>
                            <span class="privacy-consent__text">Я принимаю условия <a href="{{ route('privacy.policy') }}" target="_blank" rel="noopener">Политики обработки персональных данных</a>.</span>
                        </label>
                    @endguest
                    <button class="btn btn--primary" type="submit" data-check-in-submit @guest disabled @endguest>Отправить заявку</button>
                </form>
                @guest<p class="form-text mt-3">После отправки вы автоматически войдёте в созданный аккаунт. Чтобы позже входить с другого устройства, закрепите аккаунт в настройках — установите пароль или подключите Telegram.</p>@endguest
            @endif
        </div>
    </div>
</section>
@endsection
