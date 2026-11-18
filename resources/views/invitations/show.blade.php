@extends('theme::layouts.app')

@section('content')
<section class="section" aria-labelledby="invitation-heading">
    <div class="container" style="max-width:820px;margin-inline:auto">
        <p class="eyebrow accent">MSKBA / ПРИГЛАШЕНИЕ</p>
        <h1 id="invitation-heading">{{ $invitation->title }}</h1>
        <div class="panel" style="padding:clamp(20px,4vw,36px);margin-top:24px">
            <p><strong>Объект:</strong> {{ $invitation->targetName() }}</p>
            <p><strong>Роль:</strong> {{ match($invitation->role) { 'owner' => 'Владелец площадки', 'manager' => 'Менеджер площадки', 'booking_operator' => 'Оператор бронирований', default => 'Сотрудник площадки' } }}</p>
            @if($invitation->description)
                <p>{{ $invitation->description }}</p>
            @endif
            <p><strong>Статус:</strong> {{ $invitation->statusLabel() }} · {{ $invitation->progressLabel() }}</p>
            <p>Срок ссылки: {{ $invitation->expires_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }} (МСК)</p>

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
                    <p><a class="button primary" href="{{ route('venues.management', ['venue' => $invitation->scope_id]) }}">Перейти к подтверждению управления</a></p>
                @else
                    <h2>Завершите подтверждение аккаунта</h2>
                    <p>Email уже подтверждён. После подтверждения основных данных профиля вернитесь по ссылке приглашения, чтобы завершить назначение роли.</p>
                    <p><a class="button primary" href="{{ route('account.confirmation') }}">Подтвердить профиль</a></p>
                @endif
            @elseif($currentUser)
                <h2>Принять приглашение</h2>
                <p>Вы вошли как <strong>{{ $currentUser->username }}</strong>. При принятии адрес <strong>{{ $invitation->contact_value }}</strong>, на который было отправлено письмо, будет привязан к вашему аккаунту и подтверждён.</p>
                <form method="POST" action="{{ route('account-invitations.accept') }}">
                    @csrf
                    <p><label><input type="checkbox" name="accept_invitation" value="1" required> Я принимаю приглашение, подтверждаю, что этот email принадлежит мне, и согласен на его привязку к моему аккаунту.</label></p>
                    <button class="button primary" type="submit">Принять и подтвердить email</button>
                </form>
            @else
                <h2>Создайте аккаунт для принятия приглашения</h2>
                <p>Персональная ссылка подтверждает доступ к адресу <strong>{{ $invitation->contact_value }}</strong> после отправки формы. Другой адрес здесь использовать нельзя.</p>
                <p>Уже есть аккаунт MSKBA? <a href="{{ route('login') }}">Войдите</a> и повторно откройте ссылку из письма.</p>
                <form method="POST" action="{{ route('account-invitations.register') }}" class="app-invitation-form">
                    @csrf
                    <div><label for="inv-username">Логин</label><input id="inv-username" name="username" value="{{ old('username') }}" required minlength="3" maxlength="32" autocomplete="username"></div>
                    <div><label for="inv-password">Пароль (от 6 символов, заглавная и строчная буквы, цифра и спецсимвол)</label><input id="inv-password" name="password" type="password" required minlength="6" autocomplete="new-password"></div>
                    <div><label for="inv-password-repeat">Повтор пароля</label><input id="inv-password-repeat" name="password_confirmation" type="password" required autocomplete="new-password"></div>
                    <div><label for="inv-first-name">Имя (необязательно)</label><input id="inv-first-name" name="first_name" value="{{ old('first_name') }}" maxlength="255"></div>
                    <div><label for="inv-last-name">Фамилия (необязательно)</label><input id="inv-last-name" name="last_name" value="{{ old('last_name') }}" maxlength="255"></div>
                    <p><label><input type="checkbox" name="privacy_consent" value="1" required> Согласен с <a href="{{ route('privacy.policy') }}" target="_blank" rel="noopener noreferrer">обработкой персональных данных</a>.</label></p>
                    <p><label><input type="checkbox" name="accept_invitation" value="1" required> Принимаю приглашение и подтверждаю принадлежность мне указанного email.</label></p>
                    <button type="submit" class="button primary">Создать аккаунт и принять приглашение</button>
                </form>
                <style>
                    .app-invitation-form{display:grid;gap:16px;margin-top:24px}
                    .app-invitation-form>div{display:grid;gap:6px}
                    .app-invitation-form input:not([type=checkbox]){width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid var(--border-color,#cfcfcf);border-radius:10px;background:var(--input-bg,#fff);color:var(--text-color,#222)}
                    .app-invitation-form label{font-weight:500}
                </style>
            @endif
        </div>
    </div>
</section>
@endsection
