@php $title = 'Мои контакты'; @endphp

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

        @if(session('info'))
            <div class="alert alert-info" data-countdown-hide-on-finished>
                @if(session()->has('contactVerificationCooldownSeconds'))
                    Код уже отправлен. Новый код можно запросить через
                    <span class="countdown-wrapper">
                        <span
                            data-countdown
                            data-countdown-seconds="{{ session('contactVerificationCooldownSeconds') }}"
                        >{{ session('contactVerificationCooldownSeconds') }}</span>
                        сек.
                    </span>
                @else
                    {{ session('info') }}
                @endif
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning">
                {{ session('warning') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        <div class="account-contacts">
            <section class="account-identity-section" aria-labelledby="linkedIdentitiesTitle">
                <div>
                    <h2 id="linkedIdentitiesTitle" class="h5 mb-2">Подтверждённые способы связи и входа</h2>
                    <p class="text-muted mb-0">
                        Telegram и VK подтверждаются через официальную авторизацию. Это привязывает к аккаунту
                        неизменяемый ID, а не просто username.
                    </p>
                </div>

                <div class="account-identity-grid">
                    <article class="account-identity-card">
                        <div class="account-identity-card__heading">
                            <h3 class="h6 mb-0">Telegram</h3>
                            <span class="{{ $linkedTelegramAccount ? 'text-success' : 'text-muted' }}">
                                {{ $linkedTelegramAccount ? 'Подтверждён' : 'Не привязан' }}
                            </span>
                        </div>
                        @if($linkedTelegramAccount)
                            <p class="account-identity-card__meta mb-0">
                                {{ $linkedTelegramAccount->username ? '@'.$linkedTelegramAccount->username : 'ID '.$linkedTelegramAccount->telegram_user_id }}
                            </p>
                            <p class="text-muted mb-0">Повторное подтверждение обновит данные связи.</p>
                        @else
                            <p class="text-muted mb-0">Привяжите Telegram для входа и подтверждённой связи.</p>
                        @endif

                        @if($telegramBotUsername === '')
                            <div class="alert alert-warning mb-0">Подключение Telegram сейчас недоступно.</div>
                        @else
                            <div data-account-telegram-link data-account-telegram-link-url="{{ route('account.telegram.link', [], false) }}">
                                <script
                                    async
                                    src="https://telegram.org/js/telegram-widget.js?22"
                                    data-telegram-login="{{ $telegramBotUsername }}"
                                    data-size="large"
                                    data-radius="10"
                                    data-userpic="false"
                                    data-onauth="mskbaTelegramLink(user)"
                                ></script>
                                <p class="form-message mt-2 mb-0" data-account-telegram-link-message aria-live="polite"></p>
                            </div>
                        @endif
                    </article>

                    <article class="account-identity-card">
                        <div class="account-identity-card__heading">
                            <h3 class="h6 mb-0">VK ID</h3>
                            <span class="{{ $linkedVkAccount ? 'text-success' : 'text-muted' }}">
                                {{ $linkedVkAccount ? 'Подтверждён' : 'Не привязан' }}
                            </span>
                        </div>
                        @if($linkedVkAccount)
                            <p class="account-identity-card__meta mb-0">
                                {{ trim($linkedVkAccount->first_name.' '.$linkedVkAccount->last_name) ?: 'VK ID '.$linkedVkAccount->vk_user_id }}
                            </p>
                            <p class="text-muted mb-0">VK ID {{ $linkedVkAccount->vk_user_id }}. Повторная привязка обновит данные.</p>
                        @else
                            <p class="text-muted mb-0">Привяжите VK ID для входа и подтверждённой связи.</p>
                        @endif

                        @if($vkEnabled)
                            <div>
                                <a class="btn btn--primary btn--sm" href="{{ route('account.vk.link') }}">
                                    {{ $linkedVkAccount ? 'Обновить VK ID' : 'Привязать VK ID' }}
                                </a>
                            </div>
                        @else
                            <div class="alert alert-warning mb-0">Подключение VK ID сейчас недоступно.</div>
                        @endif
                    </article>
                </div>
            </section>

            <div class="account-contacts__list mb-5">

                <h2 class="h5 mb-2">Обычные контакты</h2>
                <p class="text-muted mb-3">Email можно подтвердить кодом из письма. Телефон и другие контакты пока используются без автоподтверждения.</p>

                @if($user->contacts->isNotEmpty())
                    <ul class="list-unstyled mb-0">
                        @foreach($user->contacts as $contact)
                            @php
                                $pendingVerification = $contact->verifications->first();
                                $hasActivePendingVerification = $pendingVerification?->expires_at?->isFuture() ?? false;
                            @endphp
                            <li class="account-contact-item mb-3">
                                <div class="account-contact-item__summary">
                                    <div class="account-contact-item__value">
                                        <span class="fw-bold">{{ $contact->type->label() }}:</span>
                                        <span>{{ $contact->displayValue() }}</span>
                                        @if($contact->label)
                                            <span class="text-muted">({{ $contact->label }})</span>
                                        @endif
                                    </div>
                                    <div class="account-contact-item__meta">
                                        @if($contact->is_primary)
                                            <span>Основной</span>
                                        @endif
                                        @if($contact->is_public)
                                            <span>Публичный</span>
                                        @endif
                                        <span class="{{ $contact->is_verified ? 'text-success' : 'text-danger' }}">{{ $contact->is_verified ? 'Подтвержден' : 'Не подтвержден' }}</span>
                                    </div>
                                </div>
                                @if(! $contact->is_verified && $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
                                    <div class="account-contact-verification">
                                        @if($hasActivePendingVerification)
                                            <div class="account-contact-verification__field mb-3">
                                                <form
                                                    id="contactVerificationConfirm{{ $contact->id }}"
                                                    method="POST"
                                                    action="{{ route('account.contacts.verification.confirm', $contact) }}"
                                                >
                                                    @csrf
                                                    <label for="contactVerificationCode{{ $contact->id }}" class="form-label">Код из письма</label>
                                                    <input
                                                        id="contactVerificationCode{{ $contact->id }}"
                                                        type="text"
                                                        name="code"
                                                        inputmode="numeric"
                                                        autocomplete="one-time-code"
                                                        maxlength="6"
                                                        class="form-control @error('code') is-invalid @enderror"
                                                        value="{{ old('code') }}"
                                                        placeholder="000000"
                                                        autofocus
                                                        required
                                                    >
                                                    @error('code')
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                                <div class="account-contact-item__actions">
                                    @if(! $contact->is_verified && $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
                                        @if($hasActivePendingVerification)
                                            <button
                                                type="submit"
                                                form="contactVerificationConfirm{{ $contact->id }}"
                                                class="btn btn--primary btn--sm"
                                            >Подтвердить</button>
                                        @endif
                                        <form method="POST" action="{{ route('account.contacts.verification.store', $contact) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--secondary btn--sm">
                                                {{ $hasActivePendingVerification ? 'Новый код' : 'Подтвердить' }}
                                            </button>
                                        </form>
                                    @endif
                                    @if(! $contact->is_primary)
                                        <form method="POST" action="{{ route('account.contacts.primary', $contact) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn--secondary btn--sm">Сделать основным</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn--secondary btn--sm">Удалить</button>
                                    </form>
                                </div>
                                @if(!$loop->last)
                                    <hr class="w-100 mt-3 mb-0">
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mb-0">Контакты пока не добавлены.</p>
                @endif
            </div>

            <div class="account-contacts__form">
                
                <hr>

                <h2 class="h5 mb-3">Добавить контакт</h2>

                <form method="POST" action="{{ route('account.contacts.store') }}">
                    @csrf

                    <div class="mb-3 field">
                        <label for="contactType" class="form-label">Тип</label>
                        <select id="contactType" name="type" class="form-select @error('type') is-invalid @enderror" required>
                            @foreach($contactTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3 field">
                        <label for="contactValue" class="form-label">Контакт</label>
                        <input
                            id="contactValue"
                            type="text"
                            name="value"
                            class="form-control @error('value') is-invalid @enderror"
                            value="{{ old('value') }}"
                            required
                        >
                        @error('value')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3 field">
                        <label for="contactLabel" class="form-label">Пометка</label>
                        <input
                            id="contactLabel"
                            type="text"
                            name="label"
                            class="form-control @error('label') is-invalid @enderror"
                            value="{{ old('label') }}"
                            placeholder="Например: для связи, рабочий, личный"
                        >
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn--primary btn--sm">Добавить</button>
                </form>
            </div>
        </div>

    @endif

@endsection
