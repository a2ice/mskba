@extends('theme::layouts.account', ['title' => 'Настройки'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Настройки',
        'intro' => 'Базовые параметры аккаунта и каналы уведомлений.',
    ])
@endsection

@section('account-content')
    @php
        $canonicalUser = $user?->canonical();
        $designThemes = app(\App\Presentation\Theming\DesignThemeManager::class)->options();
        $selectedAppearance = $canonicalUser?->design_theme_id;
        $notificationSettings = $canonicalUser
            ? \App\Modules\Identity\Domain\Models\UserNotificationSetting::query()->where('user_id', $canonicalUser->id)->first()
            : null;
        $notificationOptions = \App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::cases();
        $defaultNotificationPreference = \App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::ALL->value;
        $emailPreference = old('email_notifications', $notificationSettings?->email_notifications?->value ?? $defaultNotificationPreference);
        $telegramPreference = old('messenger_notifications', $notificationSettings?->messenger_notifications?->value ?? $defaultNotificationPreference);
        $verifiedEmail = $canonicalUser?->identityContactsQuery()
            ->where('type', \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL->value)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderByDesc('verified_at')
            ->first();
        $telegramAccount = $canonicalUser
            ? app(\App\Modules\Telegram\Application\Services\TelegramNotificationRecipientResolver::class)->resolve($canonicalUser)
            : null;
        $transferPrivacy = $canonicalUser?->privacySettings()
            ->where('type', \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS->value)
            ->first()?->visibility;
        $requestsAllowed = $transferPrivacy === null || $transferPrivacy === \App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum::EVERYONE;
    @endphp

    @if(session('status'))
        <p class="app-player-workspace__notice" role="status">{{ session('status') }}</p>
    @endif

    <section class="panel app-appearance-settings app-settings-panel" aria-labelledby="basic-settings-heading">
        <h2 id="basic-settings-heading">Базовые настройки</h2>
        <div class="app-settings-panel__grid">
            <form action="{{ route('account.settings.homepage.update') }}" method="POST" class="app-settings-panel__field">
                @csrf
                @method('PATCH')
                <label for="homepage-preference">Главная страница</label>
                <span class="select-control app-appearance-settings__select">
                    <select id="homepage-preference" name="homepage_preference" required>
                        @foreach (\App\Modules\Identity\Application\Services\HomepagePreference::OPTIONS as $value => $label)
                            <option value="{{ $value }}" @selected(old('homepage_preference', $canonicalUser ? app(\App\Modules\Identity\Application\Services\HomepagePreference::class)->selected($canonicalUser) : 'account') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
                </span>
                @error('homepage_preference') <p class="error" role="alert">{{ $message }}</p> @enderror
                <button class="button primary" type="submit">Сохранить</button>
                <p class="muted">Обычную главную можно открыть в любое время: <a href="{{ route('homepage.original') }}">Посмотреть главную MSKBA</a>.</p>
            </form>

            <form action="{{ route('account.settings.appearance.update') }}" method="POST"
                  class="app-settings-panel__field" data-design-theme-picker>
                @csrf
                @method('PATCH')
                <label for="design-theme-id">Оформление сайта</label>
                <span class="select-control app-appearance-settings__select">
                    <select id="design-theme-id" name="design_theme_id" data-current="{{ $selectedAppearance }}">
                        <option value="" @selected($selectedAppearance === null)>Как установлено на сайте</option>
                        @foreach ($designThemes as $id => $label)
                            <option value="{{ $id }}" @selected((int) $selectedAppearance === (int) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
                </span>
                <button class="button primary" type="submit">Сохранить</button>
                <p class="muted" role="status" aria-live="polite" data-theme-preference-status></p>
            </form>
        </div>
    </section>

    <section class="panel app-appearance-settings app-settings-panel" aria-labelledby="password-settings-heading">
        <h2 id="password-settings-heading">Пароль и безопасность</h2>
        <p class="muted">{{ $canonicalUser?->password ? 'Для смены пароля укажите текущий пароль.' : 'Для этого аккаунта пароль ещё не установлен. Установите его для входа по логину или подтверждённому контакту.' }}</p>
        <form action="{{ route('account.settings.password.update') }}" method="POST" class="app-settings-panel__password-form">
            @csrf
            @method('PUT')
            <div class="app-settings-panel__grid">
                @if($canonicalUser?->password)
                    <div class="app-settings-panel__field">
                        <label for="settings-current-password">Текущий пароль</label>
                        <input id="settings-current-password" type="password" name="current_password" autocomplete="current-password" required maxlength="255">
                        @error('current_password') <p class="error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="app-settings-panel__field">
                    <label for="settings-new-password">Новый пароль</label>
                    <input id="settings-new-password" type="password" name="password" autocomplete="new-password" required minlength="{{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MIN_LENGTH }}" maxlength="{{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MAX_LENGTH }}">
                    @error('password') <p class="error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="app-settings-panel__field">
                    <label for="settings-password-confirmation">Повторите новый пароль</label>
                    <input id="settings-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="{{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MIN_LENGTH }}" maxlength="{{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MAX_LENGTH }}">
                    @error('password_confirmation') <p class="error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="muted">Не менее {{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MIN_LENGTH }} символов: заглавная и строчная латинские буквы, цифра и специальный символ.</p>
            <button class="button primary" type="submit">{{ $canonicalUser?->password ? 'Изменить пароль' : 'Установить пароль' }}</button>
        </form>
    </section>

    <section class="panel app-appearance-settings app-settings-panel" aria-labelledby="notification-settings-heading">
        <h2 id="notification-settings-heading">Уведомления</h2>
        <p class="muted">Выберите типы уведомлений для подключённых каналов. Доставка доступна только на подтверждённые контакты.</p>
        <div class="app-settings-panel__in-app">
            <div>
                <strong>На сайте</strong>
                <p class="muted">Центр уведомлений и toast при новых событиях</p>
            </div>
            <span class="app-settings-panel__enabled">Всегда включено</span>
        </div>
        <form action="{{ route('account.settings.notifications.update') }}" method="POST" class="app-settings-panel__notification-form">
            @csrf
            @method('PUT')
            <div class="app-settings-panel__grid">
                <div class="app-settings-panel__field">
                    <label for="email-notifications">Электронная почта</label>
                    <p class="muted app-settings-panel__contact">
                        {{ $verifiedEmail ? $verifiedEmail->value.' · подтверждена' : 'Нет подтверждённого email. Добавьте и подтвердите контакт в профиле.' }}
                    </p>
                    <span class="select-control app-appearance-settings__select">
                        <select id="email-notifications" name="email_notifications" required>
                            @foreach ($notificationOptions as $option)
                                <option value="{{ $option->value }}" @selected($emailPreference === $option->value)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
                    </span>
                    @error('email_notifications') <p class="error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="app-settings-panel__field">
                    <label for="telegram-notifications">Telegram</label>
                    <p class="muted app-settings-panel__contact">
                        {{ $telegramAccount ? 'Личный чат с ботом доступен · подтверждён' : 'Для доставки подключите Telegram и откройте личный чат с ботом.' }}
                    </p>
                    <span class="select-control app-appearance-settings__select">
                        <select id="telegram-notifications" name="messenger_notifications" required>
                            @foreach ($notificationOptions as $option)
                                <option value="{{ $option->value }}" @selected($telegramPreference === $option->value)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
                    </span>
                    @error('messenger_notifications') <p class="error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <button class="button primary" type="submit">Сохранить уведомления</button>
        </form>
    </section>

    <section class="panel app-balance-privacy app-settings-panel" aria-labelledby="balance-privacy-title">
        <h2 id="balance-privacy-title">Запросы переводов</h2>
        <p>Управляйте тем, могут ли другие участники присылать вам запросы на перевод бонусов. Это не разрешение автоматически списывать баллы.</p>
        <form action="{{ route('account.settings.transfer-request-privacy.update') }}" method="POST">
            @csrf
            <label class="app-player-workspace__checkbox"><input type="checkbox" name="allow_requests" value="1" @checked($requestsAllowed)> Разрешить запрос на перевод баллов</label>
            <button class="button primary" type="submit">Сохранить</button>
        </form>
    </section>

    <section class="panel app-appearance-settings app-settings-panel" aria-labelledby="account-delete-heading">
        <h2 id="account-delete-heading">Удаление аккаунта</h2>
        <p class="muted">После удаления профиль исчезнет с сайта, а доступ будет закрыт на всех устройствах. История бронирований, переписки и операций сохранится. Восстановить аккаунт сможет администратор.</p>
        <form action="{{ route('account.destroy') }}" method="POST" data-account-delete-form>
            @csrf
            @method('DELETE')
            <button type="button" class="button danger" data-account-delete-open>Удалить аккаунт</button>
        </form>
    </section>

    <dialog class="mskba-modal app-contact-wizard__dialog app-account-contacts__delete-dialog"
            data-account-delete-dialog aria-labelledby="account-delete-dialog-heading"
            aria-describedby="account-delete-dialog-description">
        <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
            <h2 id="account-delete-dialog-heading" class="eyebrow accent mskba-auth-heading">Удаление аккаунта</h2>
            <button type="button" class="icon-button mskba-auth-close app-contact-wizard__close"
                    data-account-delete-cancel aria-label="Закрыть окно">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>
        <div class="mskba-modal__body app-contact-wizard__body">
            <p id="account-delete-dialog-description">Вы уверены, что хотите удалить аккаунт?</p>
            <p class="muted">Вы сразу выйдете из системы на всех устройствах, а ваш профиль исчезнет из публичных разделов MSKBA.</p>
        </div>
        <footer class="mskba-modal__footer app-contact-wizard__footer app-account-contacts__delete-footer">
            <button type="button" class="button secondary" data-account-delete-cancel>Отмена</button>
            <button type="button" class="button danger" data-account-delete-confirm>Да, удалить аккаунт</button>
        </footer>
    </dialog>
@endsection
