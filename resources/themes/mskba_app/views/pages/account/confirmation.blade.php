@extends('theme::layouts.account', ['title' => 'Подтверждение аккаунта'])

@php
    $isConfirmed = $user?->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED;
    $profile = $user?->profile;
@endphp

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Подтверждение аккаунта',
        'intro' => 'Проверь и заполни данные. После подтверждения имя, фамилию, дату рождения и пол можно будет изменить только по заявке.',
    ])
@endsection

@section('account-content')
    <div class="app-confirmation">
        @if (isset($error))
            <div class="notice app-confirmation__notice app-confirmation__notice--error" role="alert">{{ $error['message'] }}</div>
        @endif
        @foreach (['status', 'error', 'info'] as $noticeType)
            @if (session($noticeType))
                <div class="notice app-confirmation__notice {{ $noticeType === 'error' ? 'app-confirmation__notice--error' : '' }}"
                     role="{{ $noticeType === 'error' ? 'alert' : 'status' }}">{{ session($noticeType) }}</div>
            @endif
        @endforeach

        @if ($user && $isConfirmed)
            <section class="panel app-confirmation__card" aria-labelledby="confirmation-finished-title">
                <div class="app-confirmation__card-heading">
                    <h2 id="confirmation-finished-title">Статус пользователя</h2>
                    <span class="app-confirmation__state app-confirmation__state--done">Подтверждён</span>
                </div>
                <p>Аккаунт уже подтверждён. Изменение защищённых данных доступно в профиле по заявке.</p>
                <a class="button secondary" href="{{ route('account.profile') }}">Перейти в профиль</a>
            </section>
        @elseif ($user)
            <section class="panel app-confirmation__card" aria-labelledby="confirmation-contact-title">
                <div class="app-confirmation__card-heading">
                    <h2 id="confirmation-contact-title">Основной контакт</h2>
                    <span class="app-confirmation__state {{ $primaryVerifiedContact ? 'app-confirmation__state--done' : '' }}">
                        {{ $primaryVerifiedContact ? 'Выполнено' : 'Обязательно' }}
                    </span>
                </div>
                <p>Достаточно одного подтверждённого <strong>основного</strong> контакта: Email, Telegram или другого поддерживаемого канала.</p>

                @if ($primaryVerifiedContact)
                    <p class="app-confirmation__value">{{ $primaryVerifiedContact->type->label() }}: {{ $primaryVerifiedContact->displayValue() }}</p>
                    <a href="{{ route('account.contacts') }}" class="app-confirmation__contact-link">Управлять контактами</a>
                @else
                    @if ($primaryContact)
                        <p class="app-confirmation__value">{{ $primaryContact->type->label() }}: {{ $primaryContact->displayValue() }} — не подтверждён</p>
                    @endif
                    @include('theme::pages.account.partials.confirmation-contact-dialog')
                    <a href="{{ route('account.contacts') }}" class="app-confirmation__contact-link">Управлять контактами и выбрать основной</a>
                @endif
                @error('contact') <p class="app-confirmation__error" role="alert">{{ $message }}</p> @enderror
            </section>

            <form method="POST" action="{{ route('account.confirmation.complete') }}" class="app-confirmation__form">
                @csrf
                <section class="panel app-confirmation__card" aria-labelledby="confirmation-first-title">
                    <div class="app-confirmation__card-heading">
                        <h2 id="confirmation-first-title">Имя</h2>
                        <span class="app-confirmation__state {{ filled($profile?->first_name) ? 'app-confirmation__state--done' : '' }}">{{ filled($profile?->first_name) ? 'Заполнено' : 'Обязательно' }}</span>
                    </div>
                    <div class="field-group">
                        <label for="confirmation-first-name">Имя</label>
                        <input id="confirmation-first-name" type="text" name="first_name" required maxlength="255"
                               autocomplete="given-name" value="{{ old('first_name', $profile?->first_name) }}">
                        @error('first_name') <small class="app-confirmation__error">{{ $message }}</small> @enderror
                    </div>
                </section>

                <section class="panel app-confirmation__card" aria-labelledby="confirmation-last-title">
                    <div class="app-confirmation__card-heading">
                        <h2 id="confirmation-last-title">Фамилия</h2>
                        <span class="app-confirmation__state {{ filled($profile?->last_name) ? 'app-confirmation__state--done' : '' }}">{{ filled($profile?->last_name) ? 'Заполнено' : 'Обязательно' }}</span>
                    </div>
                    <div class="field-group">
                        <label for="confirmation-last-name">Фамилия</label>
                        <input id="confirmation-last-name" type="text" name="last_name" required maxlength="255"
                               autocomplete="family-name" value="{{ old('last_name', $profile?->last_name) }}">
                        @error('last_name') <small class="app-confirmation__error">{{ $message }}</small> @enderror
                    </div>
                </section>

                <div class="app-confirmation__details-grid">
                    <section class="panel app-confirmation__card" aria-labelledby="confirmation-birth-title">
                        <div class="app-confirmation__card-heading">
                            <h2 id="confirmation-birth-title">Дата рождения</h2>
                            <span class="app-confirmation__state {{ $profile?->birth_date ? 'app-confirmation__state--done' : '' }}">{{ $profile?->birth_date ? 'Заполнено' : 'Обязательно' }}</span>
                        </div>
                        <div class="field-group">
                            <label for="confirmation-birth-date">Дата рождения</label>
                            <input id="confirmation-birth-date" type="date" name="birth_date" required
                                   value="{{ old('birth_date', $profile?->birth_date?->toDateString()) }}"
                                   max="{{ now()->subDay()->toDateString() }}">
                            @error('birth_date') <small class="app-confirmation__error">{{ $message }}</small> @enderror
                        </div>
                    </section>
                    <section class="panel app-confirmation__card" aria-labelledby="confirmation-gender-title">
                        <div class="app-confirmation__card-heading">
                            <h2 id="confirmation-gender-title">Пол</h2>
                            <span class="app-confirmation__state {{ $profile?->gender ? 'app-confirmation__state--done' : '' }}">{{ $profile?->gender ? 'Заполнено' : 'Обязательно' }}</span>
                        </div>
                        <div class="field-group">
                            <label for="confirmation-gender">Пол</label>
                            <span class="select-control">
                                <select id="confirmation-gender" name="gender" required>
                                    <option value="">Выбери пол</option>
                                    @foreach ($genders as $gender)
                                        <option value="{{ $gender->value }}" @selected(old('gender', $profile?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                                    @endforeach
                                </select>
                            </span>
                            @error('gender') <small class="app-confirmation__error">{{ $message }}</small> @enderror
                        </div>
                    </section>
                </div>

                <div class="app-confirmation__finish">
                    @unless ($primaryVerifiedContact)
                        <p>Сначала подтверди основной контакт. Заполненные личные данные можно сохранить отдельно в профиле.</p>
                    @else
                        <p>После подтверждения изменения этих четырёх полей будут доступны только по заявке.</p>
                    @endunless
                    <button class="button primary" type="submit" @unless ($primaryVerifiedContact) disabled @endunless>Подтвердить аккаунт</button>
                </div>
            </form>
        @endif
    </div>
@endsection
