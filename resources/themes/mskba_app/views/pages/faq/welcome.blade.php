@php
    $title = 'Первые шаги';
    $metaDescription = 'Как подтвердить контакт и аккаунт, выбрать роль и настроить права на MSKBA.';
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="container app-content-page" aria-labelledby="faq-welcome-heading">
    <nav><a href="{{ route('faq.index') }}">Все инструкции FAQ</a></nav>
    <header class="app-account-overview__intro">
        <h1 id="faq-welcome-heading">Первые шаги</h1>
        <p>Что сделать после регистрации и как получить доступ к возможностям MSKBA.</p>
    </header>
    <div class="app-content-page__grid">
        <article class="panel app-content-page__card" id="contact-confirmation">
            <h2>1. Как подтвердить контакт</h2>
            <p>Откройте раздел контактов, добавьте способ связи и пройдите подтверждение. Для создания мероприятий и турниров обычно достаточно одного подтверждённого контакта, если нет персонального запрета. Для полного подтверждения аккаунта нужен подтверждённый основной контакт.</p>
            <a class="button secondary" href="{{ route('account.contacts') }}">Перейти к контактам</a>
        </article>
        <article class="panel app-content-page__card" id="participation-role">
            <h2>2. Как выбрать роль участия</h2>
            <p>В личном кабинете выберите роль участия. Для создания секции требуется активная роль тренера. Для подтверждения аккаунта игроку и тренеру дополнительно нужны дата рождения и пол.</p>
            <a class="button secondary" href="{{ route('account.roles') }}">Перейти к ролям</a>
        </article>
        <article class="panel app-content-page__card" id="account-confirmation">
            <h2>3. Как подтвердить аккаунт</h2>
            <p>Нужны подтверждённый основной контакт и выбранная роль участия. Для игрока и тренера обязательны дата рождения и пол; имя и фамилия необязательны.</p>
            <a class="button secondary" href="{{ route('account.confirmation') }}">Подтвердить аккаунт</a>
        </article>
        <article class="panel app-content-page__card" id="creation-permissions">
            <h2>4. Как работают права на создание</h2>
            <p>Права проверяются отдельно от статуса аккаунта. Создание команд и опросов по умолчанию доступно активным пользователям, а мероприятия и турниры требуют разрешения. Персональный запрет администратора имеет приоритет. Заблокированные и удалённые пользователи создавать объекты не могут.</p>
        </article>
        <article class="panel app-content-page__card" id="notifications">
            <h2>5. Следите за уведомлениями</h2>
            <p>Проверяйте системные сообщения и подсказки о следующих действиях в личном кабинете.</p>
            <a class="button secondary" href="{{ route('account.notifications') }}">Открыть уведомления</a>
        </article>
    </div>
</section>
@endsection
