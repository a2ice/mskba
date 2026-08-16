@php
    $title = 'Telegram';
    $telegramBotUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if($telegramBotUsername === '')
        <div class="alert alert-warning">Подключение Telegram сейчас недоступно.</div>
    @else
        <div data-account-telegram-link data-account-telegram-link-url="{{ route('account.telegram.link', [], false) }}">
            <p class="mb-3">
                Подтвердите свой Telegram через официальный Telegram Login. Это создаёт подтверждённую связь по неизменяемому Telegram ID,
                а не просто по @username.
            </p>

            @if(auth()->user()?->telegramAccount)
                <div class="alert alert-info mb-3">
                    Сейчас связан Telegram
                    {{ auth()->user()->telegramAccount->username ? '@'.auth()->user()->telegramAccount->username : 'ID '.auth()->user()->telegramAccount->telegram_user_id }}.
                    Повторное подтверждение обновит данные связи.
                </div>
            @endif

            <script
                async
                src="https://telegram.org/js/telegram-widget.js?22"
                data-telegram-login="{{ $telegramBotUsername }}"
                data-size="large"
                data-radius="10"
                data-userpic="false"
                data-onauth="mskbaTelegramLink(user)"
            ></script>

            <p class="form-message mt-3" data-account-telegram-link-message aria-live="polite"></p>
        </div>
    @endif
@endsection
