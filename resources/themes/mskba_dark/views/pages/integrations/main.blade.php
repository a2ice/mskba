@php
    $title = 'Telegram';
@endphp

@extends('theme::layouts.app')

@section('content')
    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <section class="integration-main inner" data-telegram-mini-app>
        <div class="integration-panel">
            <div class="integration-panel__eyebrow">Telegram</div>
            <h1>MSKBA - Main</h1>
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
