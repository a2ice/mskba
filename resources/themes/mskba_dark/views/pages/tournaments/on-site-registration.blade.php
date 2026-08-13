@extends('theme::layouts.app', ['title' => 'Регистрация на турнир · '.$tournament->title])

@section('content')
@php
    $organizer = $tournament->createdByActor?->user;
    $organizerName = trim(implode(' ', array_filter([
        $organizer?->profile?->first_name,
        $organizer?->profile?->middle_name,
        $organizer?->profile?->last_name,
    ]))) ?: $organizer?->username ?: 'не указан';
@endphp
<section class="section first-screen px-1 tournament-check-in" data-tournament-check-in @if($latestAdmission?->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::PENDING) data-pending-admission-id="{{ $latestAdmission->id }}" @endif @guest data-username-url="{{ route('tournaments.on-site.username', $tournament->routeIdentifier()) }}" @endguest>
    <div class="inner" style="max-width:760px">
        <div class="section-heading mb-4"><h1>Регистрация на турнир</h1><p class="mb-0">{{ $tournament->title }}</p></div>
        <div class="event-card">
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            @if(! $available)
                <div class="alert alert-info mb-0">
                    <strong>Регистрация на месте закрыта.</strong>
                    <p class="mt-2 mb-2">Обратитесь к организатору турнира.</p>
                    <span><strong>Организатор:</strong> {{ $organizerName }}@if($organizer?->username && $organizerName !== $organizer->username) · {{ '@'.$organizer->username }}@endif</span>
                </div>
            @elseif($latestAdmission?->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::ACCEPTED)
                <div class="alert alert-success">
                    <strong>Ваша заявка принята.</strong>
                    <p class="mt-2 mb-0">Вы допущены к участию в турнире.</p>
                </div>
                <a class="btn btn--primary" href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">Открыть турнир</a>
            @elseif($latestAdmission?->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::PENDING)
                <div class="alert alert-info">
                    <strong>Заявка отправлена.</strong>
                    <p class="mt-2 mb-0">Ожидайте решения ответственного за турнир. После обработки заявки вы получите уведомление, а результат отобразится на этой странице.</p>
                </div>
            @elseif($isBlocked)
                <div class="alert alert-danger mb-0">
                    <div>
                        <strong>Повторная регистрация заблокирована.</strong>
                        <p class="mt-2 mb-2"><strong>Причина:</strong> {{ $latestAdmission?->response_comment ?: 'не указана' }}</p>
                        <p class="mb-2">Обратитесь к организатору турнира.</p>
                        <span><strong>Организатор:</strong> {{ $organizerName }}@if($organizer?->username && $organizerName !== $organizer->username) · {{ '@'.$organizer->username }}@endif</span>
                    </div>
                </div>
            @else
                @if($latestAdmission?->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::DECLINED)
                    <div class="alert alert-danger">
                        <div>
                            <strong>Заявка отклонена.</strong>
                            <p class="mt-2 mb-2"><strong>Причина:</strong> {{ $latestAdmission?->response_comment ?: 'не указана' }}</p>
                            <p class="mb-2">Вы можете отправить заявку повторно. Если решение непонятно, обратитесь к организатору.</p>
                            <span><strong>Организатор:</strong> {{ $organizerName }}@if($organizer?->username && $organizerName !== $organizer->username) · {{ '@'.$organizer->username }}@endif</span>
                        </div>
                    </div>
                @endif
                <p>Выберите, в качестве кого хотите участвовать. Ответственный за турнир рассмотрит заявку и при необходимости добавит вас в команду.</p>
                @guest
                    <div class="alert alert-info">
                        <strong>Уже есть аккаунт?</strong>
                        <p class="mb-2">Войдите обычным способом или через Telegram. После входа вы вернётесь сюда, проверите роли и самостоятельно отправите заявку.</p>
                        <button class="btn btn--secondary btn--sm js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('tournaments.on-site.show', $tournament->routeIdentifier(), false) }}" data-check-in-auth>Войти в существующий аккаунт</button>
                    </div>
                    <h2 class="h5 mt-4">Или зарегистрируйтесь быстро</h2>
                @endguest
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
