{{-- Preserved Task 019 proof-of-concept for future Settings -> Notifications. --}}
<section class="panel privacy-onboarding__card privacy-onboarding__notifications"
                     aria-labelledby="privacy-notifications-title"
                     data-onboarding-notification-preview>
                <div>
                    <p class="eyebrow accent">03 / УВЕДОМЛЕНИЯ</p>
                    <h2 id="privacy-notifications-title">Отправлять уведомления на</h2>
                    <p class="privacy-onboarding__card-description">
                        Выбери удобные каналы. Подключение и подтверждение новых контактов
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
