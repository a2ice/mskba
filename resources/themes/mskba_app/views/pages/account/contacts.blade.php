@extends('theme::layouts.account', ['title' => 'Контакты'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Контакты',
        'intro' => 'Подтверждённые способы связи и выбор основного контакта.',
    ])
@endsection

@section('account-content')
    <div class="app-account-contacts">
        @foreach (['status', 'info', 'warning', 'error'] as $noticeType)
            @if (session($noticeType) && (! session('contactVerificationFeedbackContactId') || ! in_array($noticeType, ['info', 'error'], true)))
                <p class="notice {{ $noticeType === 'error' ? 'app-confirmation__notice--error' : '' }}"
                   role="{{ $noticeType === 'error' ? 'alert' : 'status' }}">{{ session($noticeType) }}</p>
            @endif
        @endforeach
        @if ($errors->any() && ! old('_verification_contact_id'))
            <p class="notice app-confirmation__notice--error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($user)
            <section class="panel app-account-contacts__panel" data-account-contacts-list data-account-contacts-list-url="{{ route('account.contacts') }}">
                <div class="app-confirmation__card-heading">
                    <h2>Мои контакты</h2>
                </div>
                <p>Первый подтверждённый контакт автоматически становится основным. Остальные подтверждённые контакты можно назначать основными вручную.</p>

                @forelse ($user->contacts as $contact)
                    @php
                        $pending = $contact->verifications->first();
                        $canConfirmByEmail = $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL && ! $contact->is_verified;
                        $resendSeconds = (int) ($contactResendCooldowns[$contact->id] ?? 0);
                        if ((int) session('contactVerificationFeedbackContactId') === $contact->id
                            && session()->has('contactVerificationCooldownSeconds')) {
                            $resendSeconds = max($resendSeconds, (int) session('contactVerificationCooldownSeconds'));
                        }
                    @endphp
                    <article class="app-account-contacts__item">
                        <div class="app-account-contacts__meta">
                            <strong>{{ $contact->type->label() }}: {{ $contact->displayValue() }}</strong>
                            <div class="app-account-contacts__badges">
                                @if ($contact->is_primary)
                                    <span class="app-confirmation__state {{ $contact->is_verified ? 'app-confirmation__state--done' : '' }}">{{ $contact->is_verified ? 'Основной' : 'Ожидает подтверждения для основного' }}</span>
                                @endif
                                <span class="app-confirmation__state {{ $contact->is_verified ? 'app-confirmation__state--done' : '' }}">
                                    {{ $contact->is_verified ? 'Подтверждён' : 'Не подтверждён' }}
                                </span>
                            </div>
                        </div>

                        <div class="app-account-contacts__controls">
                            @if ($canConfirmByEmail && $pending)
                                <form method="POST" class="app-account-contacts__verify"
                                      action="{{ route('account.contacts.verification.confirm', $contact) }}">
                                    @csrf
                                    <input type="hidden" name="_verification_contact_id" value="{{ $contact->id }}">
                                    <input id="contact-verify-{{ $contact->id }}" name="code" type="text"
                                           inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"
                                           maxlength="6" placeholder="000000" required
                                           aria-label="Шестизначный код из письма для {{ $contact->displayValue() }}">
                                    <button type="submit" class="app-account-contacts__icon-button app-account-contacts__icon-button--confirm"
                                            data-tooltip="Подтвердить код" aria-label="Подтвердить код">
                                        <svg aria-hidden="true"><use href="#check"/></svg>
                                    </button>
                                </form>
                            @endif

                            @if ($canConfirmByEmail)
                                <form method="POST" action="{{ route('account.contacts.verification.store', $contact) }}"
                                      data-contact-resend-form data-cooldown-seconds="{{ $resendSeconds }}">
                                    @csrf
                                    @if ($pending)
                                        <button type="submit" class="app-account-contacts__icon-button"
                                                data-contact-resend-button @disabled($resendSeconds > 0)
                                                data-tooltip="{{ $resendSeconds > 0 ? 'Ожидайте' : 'Отправить новый код' }}"
                                                aria-label="{{ $resendSeconds > 0 ? 'Отправка доступна позже' : 'Отправить новый код' }}">
                                            <svg aria-hidden="true"><use href="#refresh"/></svg>
                                        </button>
                                    @else
                                        <button type="submit" class="button secondary app-account-contacts__start-button"
                                                data-contact-resend-button>
                                            <span data-contact-resend-text>Подтвердить</span>
                                        </button>
                                    @endif
                                </form>
                            @endif

                            @if (! $contact->is_primary && $contact->is_verified)
                                <form method="POST" action="{{ route('account.contacts.primary', $contact) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="app-account-contacts__icon-button"
                                            data-tooltip="Сделать основным" aria-label="Сделать основным">
                                        <svg aria-hidden="true"><use href="#check"/></svg>
                                    </button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="app-account-contacts__icon-button app-account-contacts__icon-button--delete"
                                        data-tooltip="Удалить контакт" aria-label="Удалить контакт">
                                    <svg aria-hidden="true"><use href="#close"/></svg>
                                </button>
                            </form>
                        </div>
                        @if ($canConfirmByEmail)
                            <p class="app-account-contacts__feedback app-account-contacts__resend-hint"
                               data-contact-resend-hint role="status" aria-live="polite"
                               @if ($resendSeconds <= 0) hidden @endif>
                                Код уже отправлен. Новый код можно запросить через
                                <span data-contact-resend-countdown>{{ $resendSeconds }}</span> сек.
                            </p>
                        @endif
                        @if ($canConfirmByEmail)
                            <p class="app-account-contacts__feedback app-account-contacts__feedback--error"
                               data-contact-resend-error role="alert" hidden></p>
                        @endif
                        @if ((int) session('contactVerificationFeedbackContactId') === $contact->id)
                            @if (session('error'))
                                <p class="app-account-contacts__feedback app-account-contacts__feedback--error"
                                   role="alert">{{ session('error') }}</p>
                            @elseif (session('info') && ! session()->has('contactVerificationCooldownSeconds'))
                                <p class="app-account-contacts__feedback" role="status">{{ session('info') }}</p>
                            @endif
                        @endif
                        @if ((int) old('_verification_contact_id') === $contact->id && $errors->any())
                            <p class="app-account-contacts__feedback app-account-contacts__feedback--error"
                               role="alert">{{ $errors->first() }}</p>
                        @endif
                    </article>
                @empty
                    <p>Контакты пока не добавлены.</p>
                @endforelse
            </section>

            <section class="panel app-account-contacts__panel">
                <h2>Добавить контакт</h2>
                @include('theme::pages.account.partials.contact-add-dialog')
            </section>
        @endif
    </div>
@endsection
