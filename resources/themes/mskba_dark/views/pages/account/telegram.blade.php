@php
    $title = 'Telegram';
    $telegramBotUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
    $user = auth()->user()?->canonical();
    $linkedTelegramAccount = $user
        ? \App\Modules\Telegram\Domain\Models\TelegramAccount::query()
            ->whereIn('user_id', $user->identityIds())
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('last_auth_at')
            ->orderByDesc('updated_at')
            ->first()
        : null;
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

            @if($linkedTelegramAccount)
                <div class="alert alert-info mb-3">
                    Сейчас связан Telegram
                    {{ $linkedTelegramAccount->username ? '@'.$linkedTelegramAccount->username : 'ID '.$linkedTelegramAccount->telegram_user_id }}.
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
