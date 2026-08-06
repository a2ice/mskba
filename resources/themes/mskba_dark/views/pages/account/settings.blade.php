@php $title = 'Настройки аккаунта'; @endphp

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

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if ($user)

        @if(session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <section class="account-settings-card" aria-labelledby="account-password-title">
            <h2 id="account-password-title" class="h3 mb-3">
                {{ $user->password === null ? 'Установить пароль' : 'Изменить пароль' }}
            </h2>

            <p class="text-muted mb-4">
                @if($user->password === null)
                    После установки пароля вы сможете входить в web-версию по логину или подтверждённому контакту.
                @else
                    Для безопасности подтвердите текущий пароль перед его изменением.
                @endif
            </p>

            <form method="POST" action="{{ route('account.settings.password.update') }}" class="account-password-form">
                @csrf
                @method('PUT')

                @if($user->password !== null)
                    <div class="form-group field mb-3">
                        <label for="currentPassword" class="form-label">Текущий пароль</label>
                        <input id="currentPassword" type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password" required>
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif

                <div class="form-group field mb-3">
                    <label for="newPassword" class="form-label">Новый пароль</label>
                    <input id="newPassword" type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group field mb-4">
                    <label for="newPasswordConfirmation" class="form-label">Повторите новый пароль</label>
                    <input id="newPasswordConfirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password" required>
                </div>

                <p class="text-muted mb-4">
                    Не менее {{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MIN_LENGTH }} символов: заглавная и строчная латинские буквы, цифра и специальный символ.
                </p>

                <button type="submit" class="btn btn--primary btn--sm">
                    {{ $user->password === null ? 'Установить пароль' : 'Изменить пароль' }}
                </button>
            </form>
        </section>

        <section class="account-settings-card account-privacy" aria-labelledby="account-privacy-title">
            <h2 id="account-privacy-title" class="h3 mb-3">Настройки приватности и уведомлений</h2>
            <p class="text-muted mb-4">
                Управляйте своей видимостью, взаимодействиями и доставкой уведомлений.
            </p>

            <form method="POST" action="{{ route('account.settings.privacy.update') }}" class="account-privacy__form">
                @csrf
                @method('PUT')

                @foreach($privacySettingTypes as $type)
                    @php
                        $setting = $privacySettings->get($type->value);
                        $visibility = old(
                            "privacy.{$type->value}.visibility",
                            $setting?->visibility->value ?? $type->defaultVisibility()->value,
                        );
                        $allowedUsers = $privacyAllowedUsers->get($type->value, collect());
                    @endphp

                    <fieldset class="account-privacy__rule" data-privacy-rule data-user-search-url="{{ route('account.settings.privacy.users') }}">
                        <legend>{{ $type->label() }}</legend>
                        <p class="account-privacy__description">{{ $type->description() }}</p>

                        <label class="form-label" for="privacy-{{ $type->value }}">Доступ</label>
                        <select id="privacy-{{ $type->value }}" class="form-control account-privacy__visibility" name="privacy[{{ $type->value }}][visibility]" data-privacy-visibility>
                            @foreach($privacyVisibilities as $visibilityOption)
                                <option value="{{ $visibilityOption->value }}" @selected($visibility === $visibilityOption->value)>{{ $visibilityOption->label() }}</option>
                            @endforeach
                        </select>

                        @error("privacy.{$type->value}.visibility")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                        <div class="account-privacy__users" data-privacy-users @if($visibility !== \App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum::SELECTED_USERS->value) hidden @endif>
                            <label class="form-label" for="privacy-users-{{ $type->value }}">Разрешённые пользователи</label>
                            <div class="predictive-search__input-wrap">
                                <input id="privacy-users-{{ $type->value }}" type="text" class="form-control predictive-search__input" placeholder="Начните вводить имя или логин..." autocomplete="off" data-privacy-user-search>
                                <button type="button" class="predictive-search__control" data-privacy-user-control hidden aria-label="Очистить поиск"></button>
                                <div class="predictive-search__list account-privacy__results d-none" role="listbox" data-privacy-user-results></div>
                            </div>
                            <p class="predictive-search__message account-privacy__search-message text-muted" data-privacy-user-message>Введите не менее двух символов.</p>
                            <div class="account-privacy__selected" data-privacy-selected>
                                @foreach($allowedUsers as $allowedUser)
                                    @php
                                        $allowedName = trim(implode(' ', array_filter([
                                            $allowedUser->profile?->first_name,
                                            $allowedUser->profile?->last_name,
                                        ]))) ?: ($allowedUser->username ?: "Пользователь #{$allowedUser->id}");
                                    @endphp
                                    <span class="account-privacy__chip" data-privacy-user-id="{{ $allowedUser->id }}">
                                        <span>{{ $allowedName }}</span>
                                        @if($allowedUser->username)<small>{{ '@'.$allowedUser->username }}</small>@endif
                                        <input type="hidden" name="privacy[{{ $type->value }}][allowed_user_ids][]" value="{{ $allowedUser->id }}">
                                        <button type="button" aria-label="Убрать {{ $allowedName }}" data-privacy-user-remove>×</button>
                                    </span>
                                @endforeach
                            </div>
                            @error("privacy.{$type->value}.allowed_user_ids")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @error("privacy.{$type->value}.allowed_user_ids.*")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </fieldset>
                @endforeach

                @include('theme::pages.account.partials.messenger-notifications-setting', ['user' => $user])

                <button type="submit" class="btn btn--primary btn--sm">Сохранить настройки</button>
            </form>
        </section>

    @endif

@endsection
