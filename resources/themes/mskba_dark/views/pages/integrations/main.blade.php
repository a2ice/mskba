@php
    $title = 'Telegram';
@endphp

@extends('theme::layouts.telegram')

@section('content')
    <section
        class="integration-main"
        data-telegram-mini-app
        data-telegram-auth-url="{{ route('integrations.telegram.auth') }}"
    >
        <div class="integration-panel">
            <header class="telegram-app-header">
                <a class="telegram-app-header__logo" href="{{ route('welcome') }}" aria-label="MSKBA">
                    <img src="{{ asset('images/logo-header-cropped.png') }}" alt="MSKBA" width="420" height="165">
                </a>

                <button
                    type="button"
                    class="telegram-app-header__burger"
                    aria-label="Открыть меню"
                    aria-expanded="false"
                    aria-controls="telegram-app-menu"
                    data-telegram-menu-toggle
                >
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <nav class="telegram-app-menu" id="telegram-app-menu" aria-label="Навигация Mini App" hidden data-telegram-menu>
                    <a href="{{ route('account') }}">Аккаунт</a>
                    <a href="{{ route('venues') }}">Площадки</a>
                    <a href="{{ url('/#games') }}">Игры</a>
                    <a href="{{ url('/#teams') }}">Команды</a>
                    <a href="{{ url('/#about') }}">О нас</a>
                    <a href="{{ url('/#shop') }}">Магазин</a>
                </nav>
            </header>

            <p data-telegram-status>Проверяем Telegram-подпись и авторизуем пользователя...</p>

            <dl class="integration-summary" hidden data-telegram-summary>
                <div>
                    <dt>Telegram</dt>
                    <dd data-telegram-name>—</dd>
                </div>
                <div>
                    <dt>MSKBA user</dt>
                    <dd data-mskba-user>—</dd>
                </div>
                <div>
                    <dt>Канал регистрации</dt>
                    <dd data-registration-channel>—</dd>
                </div>
                <div>
                    <dt>Запуск</dt>
                    <dd data-telegram-launch>—</dd>
                </div>
            </dl>

            <div class="integration-panel__actions">
                <a href="{{ route('welcome') }}" class="btn btn--primary btn--sm">На главную</a>
                @if($telegramBotUsername)
                    <a href="https://t.me/{{ $telegramBotUsername }}" class="btn btn--secondary btn--sm">Открыть бота</a>
                @endif
            </div>
        </div>
    </section>
@endsection
