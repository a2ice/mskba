@php
    $title = 'Настройка приватности';
    $selected = collect($selectedTypeValues ?? []);
    $privacyOptions = $privacyOptions ?? [];
    $profileOn = $selected->contains('profile');
    $searchOn = $profileOn && ($privacyOptions['discoverability'] ?? 'nobody') === 'everyone';
    $profileFields = [
        ['avatar', 'Аватар', 'Показывать фотографию рядом с профилем'],
        ['contacts', 'Контакты', 'Открыть опубликованные контактные данные'],
        ['profile_gender', 'Пол', 'Показывать пол в публичной анкете'],
        ['profile_age', 'Возраст', 'Показывать возраст без даты рождения'],
    ];
    $playerFields = [
        ['player_characteristics', 'Спортивные характеристики', 'Рост, вес, опыт и игровые позиции'],
        ['player_teams', 'Мои команды', 'Участие в командах'],
        ['player_sections', 'Мои секции', 'Посещаемые секции и тренировки'],
        ['player_games', 'Сыгранные игры', 'История участия в играх'],
        ['player_tournaments', 'Турниры', 'Турнирные результаты'],
    ];
@endphp

<div class="privacy-onboarding privacy-onboarding--guided" aria-labelledby="privacy-title">
        <header class="privacy-onboarding__intro">
            <p class="eyebrow accent">ПОСЛЕДНИЙ ШАГ РЕГИСТРАЦИИ</p>
            <h1 id="privacy-title">{{ $title }}</h1>
            <p class="privacy-onboarding__lead">
                Решите, какую информацию о вас смогут видеть другие участники.
                Всё можно изменить позже. По умолчанию данные закрыты.
            </p>
        </header>

        <div class="notice privacy-onboarding__reminder" role="status" data-onboarding-notice>
            <svg aria-hidden="true"><use href="#bell"/></svg>
            <div>
                <strong>Остался последний шаг регистрации</strong>
                <p>Выберите настройки ниже и нажмите «Завершить регистрацию». Пока этот шаг не пройден, функции аккаунта ограничены.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="notice error-notice privacy-onboarding__errors" role="alert">
                <div>
                    <strong>Не удалось сохранить настройки</strong>
                    <p>Проверьте выбранные параметры и повторите попытку.</p>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('account.privacy.distribution.update') }}"
              class="privacy-onboarding__form" data-privacy-distribution>
            @csrf
            @method('PUT')
            <input type="hidden" name="privacy_hierarchy" value="1">

            <div class="privacy-onboarding__columns">
                <section class="panel privacy-onboarding__card privacy-onboarding__group"
                         aria-labelledby="privacy-profile-heading">
                    <div class="privacy-onboarding__group-heading">
                        <div>
                            <p class="eyebrow accent">01 / ВАШ ПРОФИЛЬ</p>
                            <h2 id="privacy-profile-heading">Видимость профиля</h2>
                            <p class="privacy-onboarding__card-description">
                                Разрешить другим участникам открывать вашу страницу.
                            </p>
                        </div>
                        <label class="check-label switch-label privacy-onboarding__root-toggle">
                            <input type="checkbox" name="public[profile]" value="1"
                                   aria-label="Открыть профиль" aria-controls="privacy-profile-children"
                                   data-privacy-profile @checked($profileOn)>
                        </label>
                    </div>

                    <div class="privacy-onboarding__children" id="privacy-profile-children"
                         data-privacy-profile-children @if (! $profileOn) hidden @endif>
                        <p class="privacy-onboarding__children-heading">Что показывать в профиле</p>
                        @foreach ($profileFields as [$key, $label, $description])
                            <label class="check-label switch-label privacy-onboarding__option" for="privacy-{{ $key }}">
                                <span class="privacy-onboarding__option-copy">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $description }}</small>
                                </span>
                                <input type="checkbox" id="privacy-{{ $key }}"
                                       name="public[{{ $key }}]" value="1"
                                       data-privacy-option data-privacy-profile-child
                                       @checked($profileOn && $selected->contains($key))>
                            </label>
                        @endforeach

                        @if ($hasPlayerRole ?? false)
                            @php $playerOn = $profileOn && $selected->contains('role_player'); @endphp
                            <div class="privacy-onboarding__role-details" data-privacy-player-details>
                                <label class="check-label switch-label privacy-onboarding__option privacy-onboarding__player-toggle"
                                       for="privacy-role_player">
                                    <span class="privacy-onboarding__option-copy">
                                        <strong>Видимость игрока</strong>
                                        <small>Показывать вашу страницу игрока</small>
                                    </span>
                                    <input type="checkbox" id="privacy-role_player" name="public[role_player]"
                                           value="1" data-privacy-option data-privacy-profile-child
                                           data-privacy-player-root aria-controls="privacy-player-children"
                                           aria-expanded="{{ $playerOn ? 'true' : 'false' }}"
                                           @checked($playerOn)>
                                </label>
                                <div class="privacy-onboarding__role-fields" id="privacy-player-children"
                                     data-privacy-player-children @if (! $playerOn) hidden @endif>
                                    @foreach ($playerFields as [$key, $label, $description])
                                        <label class="check-label switch-label privacy-onboarding__option"
                                               for="privacy-{{ $key }}">
                                            <span class="privacy-onboarding__option-copy">
                                                <strong>{{ $label }}</strong><small>{{ $description }}</small>
                                            </span>
                                            <input type="checkbox" id="privacy-{{ $key }}" name="public[{{ $key }}]"
                                                   value="1" data-privacy-option data-privacy-profile-child
                                                   data-privacy-player-child
                                                   @checked($playerOn && $selected->contains($key))>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    <p class="privacy-onboarding__closed-hint" data-privacy-profile-closed @if ($profileOn) hidden @endif>
                        Профиль закрыт. Никакие вложенные данные не публикуются.
                    </p>
                </section>

                <section @class(['panel', 'privacy-onboarding__card', 'privacy-onboarding__group', 'privacy-onboarding__group--inactive' => ! $profileOn])
                         aria-labelledby="privacy-discovery-heading"
                         data-privacy-search-group aria-disabled="{{ $profileOn ? 'false' : 'true' }}">
                    <div class="privacy-onboarding__group-heading">
                        <div>
                            <p class="eyebrow accent">02 / ПОИСК И ВЗАИМОДЕЙСТВИЯ</p>
                            <h2 id="privacy-discovery-heading">Видимость в поиске</h2>
                            <p class="privacy-onboarding__card-description">
                                Позволить находить вас в списках участников и при выборе адресатов приглашений.
                            </p>
                        </div>
                        <label class="check-label switch-label privacy-onboarding__root-toggle">
                            <input type="checkbox" name="privacy_options[discoverability]" value="everyone"
                                   aria-label="Показывать меня в поиске" aria-controls="privacy-discovery-children"
                                   data-privacy-search @checked($searchOn) @disabled(! $profileOn)>
                        </label>
                    </div>
                    <div class="privacy-onboarding__children" id="privacy-discovery-children"
                         data-privacy-search-children @if (! $searchOn) hidden @endif>
                        <p class="privacy-onboarding__children-heading">Кто может взаимодействовать со мной</p>
                        <div class="privacy-onboarding__selector-field">
                            <label for="privacy-messages">Кто может писать мне сообщения</label>
                            <span class="select-control"><select id="privacy-messages" name="privacy_options[messages]" data-privacy-search-child>
                                <option value="nobody" @selected(($privacyOptions['messages'] ?? 'nobody') === 'nobody')>Никто</option>
                                <option value="everyone" @selected(($privacyOptions['messages'] ?? 'nobody') === 'everyone')>Все участники портала</option>
                            </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span>
                            <p>Настройка для личной переписки, когда этот функционал станет доступен.</p>
                        </div>
                        <div class="privacy-onboarding__selector-field">
                            <label for="privacy-invitations">Кто может приглашать меня в группы</label>
                            <span class="select-control"><select id="privacy-invitations" name="privacy_options[group_invitations]" data-privacy-search-child>
                                <option value="nobody" @selected(($privacyOptions['group_invitations'] ?? 'nobody') === 'nobody')>Никто</option>
                                <option value="everyone" @selected(($privacyOptions['group_invitations'] ?? 'nobody') === 'everyone')>Все участники портала</option>
                            </select><svg aria-hidden="true"><use href="#chevron-down"/></svg></span>
                            <p>
                                @if ($hasPlayerRole ?? false)
                                    Сюда входят и приглашения в команды. Отдельные правила для приглашений игрока доработаем позже.
                                @else
                                    Управляет доступностью для приглашений в группы и мероприятия.
                                @endif
                            </p>
                        </div>
                        <p class="privacy-onboarding__future">Вариант «Мои связи» добавим после реализации подтверждённых связей команд, секций и участников.</p>
                    </div>
                    <p class="privacy-onboarding__closed-hint" data-privacy-search-closed @if ($searchOn) hidden @endif>
                        <span data-privacy-search-closed-text>
                            {{ $profileOn
                                ? 'Вы не появляетесь в поиске, новые сообщения и приглашения отключены.'
                                : 'Сначала откройте профиль, чтобы настроить видимость в поиске.' }}
                        </span>
                    </p>
                </section>
            </div>

            <section class="panel privacy-onboarding__card privacy-onboarding__notifications"
                     aria-labelledby="privacy-notifications-title"
                     data-onboarding-notification-preview>
                <div>
                    <p class="eyebrow accent">03 / УВЕДОМЛЕНИЯ</p>
                    <h2 id="privacy-notifications-title">Отправлять уведомления на</h2>
                    <p class="privacy-onboarding__card-description">
                        Выберите удобные каналы. Подключение и подтверждение новых контактов
                        появится в следующем обновлении — сейчас это предварительный просмотр.
                    </p>
                </div>
                @if (count($notificationContacts ?? []) > 0)
                    <div class="privacy-onboarding__verified-contacts">
                        @foreach ($notificationContacts as $channel)
                            <label class="privacy-onboarding__notify-option">
                                <span>
                                    <strong>{{ $channel['label'] }}</strong>
                                    <small>{{ $channel['value'] }} · подтверждён</small>
                                </span>
                                <input type="checkbox" data-notification-preview-channel
                                       aria-label="Уведомления: {{ $channel['label'] }}">
                            </label>
                        @endforeach
                    </div>
                @endif
                @php
                    $availableChannels = collect([
                        ['email', 'Почта', 'Добавить почту', 'Адрес электронной почты', 'email', 'name'],
                        ['telegram', 'Telegram', 'Добавить Telegram', 'Telegram username', 'text', 'username'],
                        ['vk', 'VK', 'Добавить VK', 'Ссылка на профиль VK', 'text', 'url'],
                    ])->reject(fn (array $channel): bool => collect($notificationContacts ?? [])->contains('type', $channel[0]))->values();
                @endphp
                @if ($availableChannels->isNotEmpty())
                    <div class="privacy-onboarding__notification-add-buttons" aria-label="Добавить контакт">
                        @foreach ($availableChannels as [$kind, $tabLabel, $buttonLabel, $fieldLabel, $fieldType, $autocomplete])
                            <button type="button" class="button secondary" data-notification-add="{{ $kind }}">
                                {{ $buttonLabel }}
                            </button>
                        @endforeach
                    </div>
                    <div class="privacy-onboarding__notification-workspace" data-notification-workspace hidden>
                        <div class="privacy-onboarding__notification-tabs" role="tablist"
                             aria-label="Добавляемые контакты" data-notification-tabs>
                            @foreach ($availableChannels as [$kind, $tabLabel, $buttonLabel, $fieldLabel, $fieldType, $autocomplete])
                                <div class="privacy-onboarding__notification-tab" data-notification-tab="{{ $kind }}" hidden>
                                    <button type="button" role="tab" aria-selected="false" tabindex="-1"
                                            id="onboarding-notify-tab-{{ $kind }}"
                                            aria-controls="onboarding-notify-{{ $kind }}"
                                            data-notification-select="{{ $kind }}">{{ $tabLabel }}</button>
                                    <button type="button" class="privacy-onboarding__notification-close"
                                            aria-label="Закрыть {{ $tabLabel }}"
                                            data-notification-close="{{ $kind }}">
                                        <svg aria-hidden="true"><use href="#close"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        @foreach ($availableChannels as [$kind, $tabLabel, $buttonLabel, $fieldLabel, $fieldType, $autocomplete])
                            <div id="onboarding-notify-{{ $kind }}" class="privacy-onboarding__notification-draft"
                                 role="tabpanel" aria-labelledby="onboarding-notify-tab-{{ $kind }}"
                                 data-notification-draft="{{ $kind }}" hidden>
                                <div class="field-group">
                                    <label for="onboarding-notify-input-{{ $kind }}">{{ $fieldLabel }}</label>
                                    <input id="onboarding-notify-input-{{ $kind }}"
                                           type="{{ $fieldType }}" autocomplete="off"
                                           placeholder="{{ $fieldLabel }}"
                                           data-notification-preview-input>
                                </div>
                                <div class="privacy-onboarding__otp-preview" aria-label="Будущее подтверждение контакта">
                                    <button class="button secondary" type="button" disabled>Отправить код · скоро</button>
                                    <div class="field-group">
                                        <label for="onboarding-notify-code-{{ $kind }}">Код подтверждения</label>
                                        <input id="onboarding-notify-code-{{ $kind }}" type="text" inputmode="numeric"
                                               placeholder="Код из сообщения" autocomplete="off" disabled>
                                    </div>
                                </div>
                                <p class="helper">Отправка кода, проверка и сохранение контакта пока недоступны.</p>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="privacy-onboarding__notification-hint" role="note">
                    Выбор каналов и введённые здесь контакты пока <strong>не сохраняются</strong>
                    и не влияют на отправку уведомлений.
                </p>
            </section>

            <section class="panel privacy-onboarding__card privacy-onboarding__consent"
                     aria-labelledby="distribution-consent-title">
                <div class="privacy-onboarding__consent-header">
                    <div>
                        <p class="eyebrow accent">ОТДЕЛЬНОЕ СОГЛАСИЕ</p>
                        <h2 id="distribution-consent-title">Публикация персональных данных</h2>
                        <p class="privacy-onboarding__card-description">
                            Если вы разрешили публиковать сведения в профиле,
                            необходимо отдельное добровольное согласие.
                        </p>
                    </div>
                    <span class="badge" data-privacy-count>{{ $selected->count() }} выбрано</span>
                </div>
                <div class="privacy-onboarding__consent-body" data-privacy-consent-body>
                    <label class="check-label privacy-onboarding__consent-label">
                        <input type="checkbox" name="distribution_consent" value="1"
                               @checked(old('distribution_consent'))
                               @if ($errors->has('distribution_consent')) aria-invalid="true" aria-describedby="distribution-consent-error" @endif
                               data-privacy-consent>
                        <span>Я разрешаю MSKBA распространять <strong>только выбранные выше данные</strong>
                            на условиях <a href="{{ route('personal-data.distribution-consent') }}"
                            target="_blank" rel="noopener noreferrer">отдельного согласия</a>.</span>
                    </label>
                    @error('distribution_consent')
                        <p id="distribution-consent-error" class="error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <p class="privacy-onboarding__closed-hint" data-privacy-no-consent hidden>
                    Вы ничего не публикуете — отдельное согласие не требуется.
                </p>
                @if ($hasActiveConsent ?? false)
                    <p class="privacy-onboarding__historical">Предыдущее согласие будет отозвано и заменено новым.</p>
                @endif
            </section>

            <div class="privacy-onboarding__footer">
                <div class="privacy-onboarding__footer-copy">
                    <strong>Вы управляете приватностью.</strong>
                    <span>Изменить эти настройки можно будет в аккаунте.</span>
                </div>
                <div class="privacy-onboarding__actions">
                    <button class="button primary" type="submit" name="action" value="save">Завершить регистрацию</button>
                </div>
            </div>
        </form>
    </div>
