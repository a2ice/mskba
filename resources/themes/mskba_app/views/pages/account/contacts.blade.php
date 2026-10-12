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
            @if (session($noticeType))
                <p class="notice {{ $noticeType === 'error' ? 'app-confirmation__notice--error' : '' }}"
                   role="{{ $noticeType === 'error' ? 'alert' : 'status' }}">{{ session($noticeType) }}</p>
            @endif
        @endforeach
        @if ($errors->any())
            <p class="notice app-confirmation__notice--error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($user)
            <section class="panel app-account-contacts__panel" data-account-contacts-list>
                <div class="app-confirmation__card-heading">
                    <h2>Мои контакты</h2>
                </div>
                <p>Первый подтверждённый контакт автоматически становится основным. Остальные подтверждённые контакты можно назначать основными вручную.</p>

                @forelse ($user->contacts as $contact)
                    @php
                        $pending = $contact->verifications->first();
                        $canConfirmByEmail = $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL && ! $contact->is_verified;
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
                        <div class="app-account-contacts__actions">
                            @unless ($contact->is_primary)
                                @if ($contact->is_verified)
                                    <form method="POST" action="{{ route('account.contacts.primary', $contact) }}">
                                        @csrf @method('PATCH')
                                        <button class="button secondary" type="submit">Сделать основным</button>
                                    </form>
                                @endif
                            @endunless
                            @if ($canConfirmByEmail)
                                <form method="POST" action="{{ route('account.contacts.verification.store', $contact) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">{{ $pending ? 'Отправить новый код' : 'Отправить код' }}</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}">
                                @csrf @method('DELETE')
                                <button class="button secondary" type="submit">Удалить</button>
                            </form>
                        </div>
                        @if ($canConfirmByEmail && $pending)
                            <form method="POST" class="app-account-contacts__verify" action="{{ route('account.contacts.verification.confirm', $contact) }}">
                                @csrf
                                <label class="field-group" for="contact-verify-{{ $contact->id }}">Код из письма
                                    <input id="contact-verify-{{ $contact->id }}" name="code" type="text" inputmode="numeric"
                                           pattern="[0-9]{6}" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
                                </label>
                                <button type="submit" class="button primary">Подтвердить</button>
                            </form>
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
