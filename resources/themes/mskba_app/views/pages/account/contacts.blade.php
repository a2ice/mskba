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
            <section class="panel app-account-contacts__panel">
                <div class="app-confirmation__card-heading">
                    <h2>Мои контакты</h2>
                </div>
                <p>Первый контакт автоматически становится основным. После подтверждения аккаунта основным можно назначить только подтверждённый контакт.</p>

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
                                    <span class="app-confirmation__state app-confirmation__state--done">Основной</span>
                                @endif
                                <span class="app-confirmation__state {{ $contact->is_verified ? 'app-confirmation__state--done' : '' }}">
                                    {{ $contact->is_verified ? 'Подтверждён' : 'Не подтверждён' }}
                                </span>
                            </div>
                        </div>
                        <div class="app-account-contacts__actions">
                            @unless ($contact->is_primary)
                                @if (! $user->isConfirmed() || $contact->is_verified)
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
                @unless ($user->isConfirmed())
                    <p>Для подтверждения первого контакта удобнее использовать пошаговый визард.</p>
                    <a class="button primary" href="{{ route('account.confirmation') }}">Открыть подтверждение аккаунта</a>
                @endunless
                <form method="POST" class="app-account-contacts__add" action="{{ route('account.contacts.store') }}">
                    @csrf
                    <div class="field-group">
                        <label for="new-contact-type">Тип контакта</label>
                        <select id="new-contact-type" name="type" required>
                            <option value="email">Email</option>
                            <option value="phone">Телефон (без SMS-подтверждения)</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="new-contact-value">Адрес или номер</label>
                        <input id="new-contact-value" name="value" type="text" maxlength="255" value="{{ old('value') }}" required>
                    </div>
                    <button class="button secondary" type="submit">Добавить</button>
                </form>
                <p class="app-account-contacts__note">Telegram и VK подтверждаются через официальную авторизацию. Если контакт уже привязан, он появится в списке автоматически.</p>
            </section>
        @endif
    </div>
@endsection
