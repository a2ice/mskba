@extends('theme::layouts.app')

@section('content')
<section class="section app-invitation" aria-labelledby="invitation-heading">
    <div class="container app-invitation__container">
        <h1 id="invitation-heading" class="app-invitation__title">{{ $invitation->title }}</h1>
        <div class="panel app-invitation__panel">
            <div class="app-invitation__details">
            <p><strong>Объект:</strong> {{ $invitation->targetName() }}</p>
            <p><strong>Роль:</strong> {{ match($invitation->role) { 'owner' => 'Владелец площадки', 'manager' => 'Менеджер площадки', 'booking_operator' => 'Оператор бронирований', default => 'Сотрудник площадки' } }}</p>
            @if($invitation->description)
                <p>{{ $invitation->description }}</p>
            @endif
            <p><strong>Статус:</strong> {{ $invitation->statusLabel() }} · {{ $invitation->progressLabel() }}</p>
            <p>Срок ссылки: {{ $invitation->expires_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }} (МСК)</p>
            </div>

            @foreach(['status', 'warning', 'error'] as $message)
                @if(session($message))
                    <p class="notice" role="{{ $message === 'error' ? 'alert' : 'status' }}">{{ session($message) }}</p>
                @endif
            @endforeach
            @if($errors->any())
                <div class="notice" role="alert">{{ $errors->first() }}</div>
            @endif

            @if($invitation->status === 'revoked')
                <p>Приглашение отозвано администратором. Обратитесь за новым приглашением.</p>
            @elseif($invitation->status === 'completed')
                <p>Процедура завершена. Доступ предоставлен согласно назначенным полномочиям.</p>
                <p><a class="button primary" href="{{ route('account') }}">Открыть личный кабинет</a></p>
            @elseif($invitation->isExpired() && $invitation->accepted_user_id === null)
                <p>Срок действия ссылки истёк. Попросите администратора обновить приглашение: новая ссылка заменит старую.</p>
            @elseif($invitation->accepted_user_id !== null)
                @if(!$currentUser || !$currentUser->isSameIdentity((int) $invitation->accepted_user_id))
                    <p>Это приглашение уже закреплено за другим аккаунтом. Войдите в тот аккаунт, который принял приглашение.</p>
                    <p><a class="button primary" href="{{ route('login') }}">Войти</a></p>
                @elseif($invitation->role === 'owner')
                    <h2>Следующий этап — подтверждение управления площадкой</h2>
                    <p>Адрес электронной почты подтверждён. Теперь подайте заявку с основанием владения или управления. После проверки и одобрения будут выданы полномочия владельца.</p>
                    <p><a class="button primary" href="{{ route('account.venue-contracts.show', ['venue' => $invitation->scope_id]) }}">Перейти к подтверждению управления</a></p>
                @elseif($invitation->progress === 'awaiting_authorization')
                    <h2>Ожидается согласование полномочий</h2>
                    <p>Приглашение принято. Для назначения роли требуется дополнительная проверка со стороны портала. Повторно принимать приглашение не нужно.</p>
                    <p><a class="button primary" href="{{ route('account') }}">Открыть личный кабинет</a></p>
                @else
                    <h2>Завершите подтверждение аккаунта</h2>
                    <p>Email уже подтверждён. После подтверждения основных данных профиля вернитесь по ссылке приглашения, чтобы завершить назначение роли.</p>
                    <p><a class="button primary" href="{{ route('account.confirmation') }}">Подтвердить профиль</a></p>
                @endif
            @elseif(!$started)
                <h2>Вы получили приглашение</h2>
                @if($invitation->issuer?->nickname || $invitation->issuer?->username)
                    <p>Вас приглашает <strong>{{ $invitation->issuer->nickname ?: $invitation->issuer->username }}</strong>.</p>
                @endif
                <p>Принятие приглашения позволит начать подключение к площадке. На следующем шаге вы сможете создать аккаунт или продолжить с существующим.</p>
                <form method="POST" action="{{ route('account-invitations.interest') }}">
                    @csrf
                    <button class="button primary" type="submit">Принять приглашение</button>
                </form>
            @elseif($currentUser)
                <h2>Принять приглашение</h2>
                <p>Вы вошли как <strong>{{ $currentUser->username }}</strong>. При принятии адрес <strong>{{ $invitation->contact_value }}</strong>, на который было отправлено письмо, будет привязан к вашему аккаунту и подтверждён.</p>
                <form method="POST" action="{{ route('account-invitations.accept') }}">
                    @csrf
                    <p><label><input type="checkbox" name="accept_invitation" value="1" required> Я принимаю приглашение, подтверждаю, что этот email принадлежит мне, и согласен на его привязку к моему аккаунту.</label></p>
                    <button class="button primary" type="submit">Принять и подтвердить email</button>
                </form>
            @else
                <h2>Принятие приглашения</h2>
                <p>Персональная ссылка подтверждает доступ к адресу <strong>{{ $invitation->contact_value }}</strong> после отправки формы. Другой адрес здесь использовать нельзя.</p>
                <p>Уже есть аккаунт MSKBA? <a href="{{ route('login') }}">Войдите</a> и повторно откройте ссылку из письма.</p>
                <form method="POST" action="{{ route('account-invitations.register') }}" class="app-invitation-form">
                    @csrf
                    <div>
                        <label for="inv-proposed-username">Предложенный логин / никнейм</label>
                        <input id="inv-proposed-username" value="{{ $suggestedUsername }}" readonly>
                        <input type="hidden" name="proposed_username" value="{{ $suggestedUsername }}">
                        <p>Для входа также можно использовать email приглашения.</p>
                        <label><input id="inv-custom-username-toggle" type="checkbox" name="custom_username" value="1" @checked(old('custom_username'))> Хочу установить свой логин / никнейм</label>
                        <div class="app-invitation-custom-username">
                            <label for="inv-username">Свой логин</label>
                            <input id="inv-username" name="username" value="{{ old('custom_username') ? old('username') : '' }}" minlength="3" maxlength="32" autocomplete="username" @error('username') aria-invalid="true" aria-describedby="inv-username-error" @enderror>
                        </div>
                        @error('username') <small id="inv-username-error" class="app-invitation-form__error">{{ $message }}</small> @enderror
                    </div>
                    <div><label for="inv-password">Пароль (от 6 символов, заглавная и строчная буквы, цифра и спецсимвол)</label><input id="inv-password" @error('password') aria-invalid="true" aria-describedby="inv-password-error" @enderror name="password" type="password" required minlength="6" autocomplete="new-password">@error('password') <small id="inv-password-error" class="app-invitation-form__error">{{ $message }}</small> @enderror</div>
                    <div><label for="inv-password-repeat">Повтор пароля</label><input id="inv-password-repeat" @error('password_confirmation') aria-invalid="true" aria-describedby="inv-password-repeat-error" @enderror name="password_confirmation" type="password" required autocomplete="new-password">@error('password_confirmation') <small id="inv-password-repeat-error" class="app-invitation-form__error">{{ $message }}</small> @enderror</div>
                    <div><label for="inv-first-name">Имя (необязательно)</label><input id="inv-first-name" @error('first_name') aria-invalid="true" aria-describedby="inv-first-name-error" @enderror name="first_name" value="{{ old('first_name') }}" maxlength="255">@error('first_name') <small id="inv-first-name-error" class="app-invitation-form__error">{{ $message }}</small> @enderror</div>
                    <div><label for="inv-last-name">Фамилия (необязательно)</label><input id="inv-last-name" @error('last_name') aria-invalid="true" aria-describedby="inv-last-name-error" @enderror name="last_name" value="{{ old('last_name') }}" maxlength="255">@error('last_name') <small id="inv-last-name-error" class="app-invitation-form__error">{{ $message }}</small> @enderror</div>
                    <p><label><input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))> Согласен с <a class="app-invitation__privacy-link" href="{{ route('privacy.policy') }}" target="_blank" rel="noopener noreferrer">обработкой персональных данных</a>.</label></p>
                    <p><label><input type="checkbox" name="accept_invitation" value="1" required @checked(old('accept_invitation'))> Принимаю приглашение и подтверждаю принадлежность мне указанного email.</label></p>
                    <button type="submit" class="button primary">Создать аккаунт и принять приглашение</button>
                </form>
            @endif
        </div>
    </div>
</section>
<style>
    /* Token-free invitation onboarding: scoped to this page and both appearances. */
    .app-invitation.section { padding-top:clamp(28px,3.5vw,52px); padding-bottom:clamp(48px,5vw,80px); }
    .app-invitation__privacy-link { color:var(--accent,#ea5a00) !important; text-decoration:underline !important; text-underline-offset:3px; font-weight:600; }
    .app-invitation__privacy-link:hover { color:var(--accent-hover,#b84900) !important; }
    .app-invitation__container { max-width:820px; margin-inline:auto; }
    .app-invitation__title { margin:0 0 22px; font-size:clamp(30px,3.2vw,46px); line-height:1.12; letter-spacing:-.03em; overflow-wrap:anywhere; }
    .app-invitation__panel { padding:clamp(20px,3vw,36px); margin-top:0; }
    .app-invitation__details { display:grid; gap:5px; padding-bottom:20px; margin-bottom:20px; border-bottom:1px solid var(--line,#ddd); }
    .app-invitation__details p { margin:0; line-height:1.5; overflow-wrap:anywhere; }
    .app-invitation__panel > h2 { margin:18px 0 10px; font-size:clamp(21px,2.4vw,27px); line-height:1.2; }
    .app-invitation__panel > p { margin:8px 0; line-height:1.55; }
    .app-invitation__panel .notice { margin-block:12px; }
    .app-invitation-form { display:grid; gap:17px; margin-top:24px; }
    .app-invitation-form > div { display:grid; gap:7px; min-width:0; }
    .app-invitation-form input:not([type=checkbox]) { width:100%; min-width:0; box-sizing:border-box; padding:12px 14px; border:1px solid var(--control-line,var(--line,#cfcfcf)); border-radius:10px; background:var(--field,var(--surface,#fff)); color:var(--text,#222); }
    .app-invitation-form input[aria-invalid="true"] { border-color:var(--danger,#d33); }
    .app-invitation-form label { font-weight:500; line-height:1.5; }
    .app-invitation-form > p { margin:0; line-height:1.5; }
    .app-invitation-custom-username { display:grid; gap:7px; }
    @supports selector(:has(*)) {
        .app-invitation-form div:has(> label > #inv-custom-username-toggle:not(:checked)) > .app-invitation-custom-username { display:none; }
    }
    .app-invitation-form input:not([type=checkbox]) { font-size:16px; }
    .app-invitation-form__error { display:block; color:var(--danger,#d33); font-size:13px; }
    .app-invitation-form .button { justify-self:start; max-width:100%; white-space:normal; }
    @media (max-width:600px) {
        .app-invitation.section { padding-top:28px; }
        .app-invitation__title { margin-bottom:18px; }
        .app-invitation-form .button { width:100%; }
    }
</style>
@endsection
