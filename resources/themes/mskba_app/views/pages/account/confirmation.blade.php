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
            @php
                $profileFields = [
                    ['name' => 'first_name', 'id' => 'confirmation-first-name', 'label' => 'Имя', 'required' => true, 'inputType' => 'text', 'auto' => 'given-name', 'value' => (string) ($profile?->first_name ?? '')],
                    ['name' => 'last_name', 'id' => 'confirmation-last-name', 'label' => 'Фамилия', 'required' => true, 'inputType' => 'text', 'auto' => 'family-name', 'value' => (string) ($profile?->last_name ?? '')],
                    ['name' => 'middle_name', 'id' => 'confirmation-middle-name', 'label' => 'Отчество', 'required' => false, 'inputType' => 'text', 'auto' => 'additional-name', 'value' => (string) ($profile?->middle_name ?? '')],
                    ['name' => 'birth_date', 'id' => 'confirmation-birth-date', 'label' => 'Дата рождения', 'required' => true, 'inputType' => 'date', 'auto' => 'bday', 'value' => $profile?->birth_date?->toDateString() ?? ''],
                    ['name' => 'gender', 'id' => 'confirmation-gender', 'label' => 'Пол', 'required' => true, 'inputType' => 'select', 'auto' => 'sex', 'value' => $profile?->gender?->value ?? ''],
                ];
                $missingRequired = collect($profileFields)
                    ->filter(fn (array $field): bool => $field['required'] && blank(trim($field['value'])));
                $missingTotal = $missingRequired->count() + ($primaryVerifiedContact ? 0 : 1);
                $firstMissing = $missingRequired->first();
            @endphp

            <form id="confirmation-complete-form" method="POST" action="{{ route('account.confirmation.complete') }}"
                  class="app-confirmation__form" data-confirmation-profile
                  data-save-url="{{ route('account.profile.update') }}"
                  data-has-verified-contact="{{ $primaryVerifiedContact ? 'true' : 'false' }}">
                @csrf
                <section class="panel app-confirmation__card" aria-labelledby="confirmation-profile-title">
                    <div class="app-confirmation__card-heading">
                        <h2 id="confirmation-profile-title">Профиль</h2>
                        <span class="app-confirmation__state {{ $missingRequired->isEmpty() ? 'app-confirmation__state--done' : '' }}" data-confirmation-profile-state>
                            {{ $missingRequired->isEmpty() ? 'Заполнено' : 'Требует заполнения' }}
                        </span>
                    </div>
                    <div class="app-confirmation__profile-fields">
                        @foreach ($profileFields as $field)
                            <div class="app-confirmation__profile-field field-group" data-confirmation-field data-inline-profile-field
                                 data-field-name="{{ $field['name'] }}"
                                 data-saved-value="{{ $field['value'] }}"
                                 data-required="{{ $field['required'] ? 'true' : 'false' }}">
                                <label for="{{ $field['id'] }}">
                                    {{ $field['label'] }}
                                    @unless($field['required'])
                                        <span class="app-confirmation__optional">Необязательно</span>
                                    @endunless
                                </label>
                                <div class="app-confirmation__input-row">
                                    <span class="app-confirmation__input-wrap {{ $field['inputType'] === 'select' ? 'select-control' : '' }} {{ $field['inputType'] === 'date' ? 'app-confirmation__input-wrap--date' : '' }}">
                                        @if ($field['inputType'] === 'select')
                                            <select id="{{ $field['id'] }}" name="{{ $field['name'] }}" autocomplete="{{ $field['auto'] }}" data-confirmation-input data-inline-profile-input>
                                                <option value="">Выбери пол</option>
                                                @foreach ($genders as $gender)
                                                    <option value="{{ $gender->value }}" @selected($field['value'] === $gender->value)>{{ $gender->label() }}</option>
                                                @endforeach
                                            </select>
                                            <svg class="app-confirmation__select-arrow" aria-hidden="true"><use href="#chevron-down"/></svg>
                                        @else
                                            <input id="{{ $field['id'] }}" data-confirmation-input data-inline-profile-input name="{{ $field['name'] }}"
                                                   type="{{ $field['inputType'] }}"
                                                   @if ($field['inputType'] === 'text') maxlength="255" @endif
                                                   autocomplete="{{ $field['auto'] }}"
                                                   @if($field['inputType'] === 'date') max="{{ now()->subDay()->toDateString() }}" @endif
                                                   value="{{ old($field['name'], $field['value']) }}">
                                        @endif
                                        <button type="button" class="app-confirmation__save-field" data-confirmation-save-field data-inline-profile-save
                                                aria-label="Сохранить {{ mb_strtolower($field['label']) }}"
                                                title="Сохранить {{ mb_strtolower($field['label']) }}"
                                                disabled>
                                            <svg class="app-confirmation__save-icon" aria-hidden="true"><use href="#save"/></svg>
                                            <span class="app-confirmation__save-loader" aria-hidden="true" data-confirmation-loading data-inline-profile-loading hidden></span>
                                            <span class="app-confirmation__save-cooldown" data-inline-profile-cooldown aria-hidden="true" hidden></span>
                                        </button>
                                    </span>
                                </div>
                                <small class="app-confirmation__error" data-confirmation-error data-inline-profile-error role="alert" hidden></small>
                                @error($field['name']) <small class="app-confirmation__error">{{ $message }}</small> @enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="app-confirmation__profile-actions">
                        <p data-confirmation-feedback data-inline-profile-feedback role="status" aria-live="polite"></p>
                        <button class="button secondary" type="button" data-confirmation-save-all data-inline-profile-save-all disabled>Сохранить всё</button>
                    </div>
                </section>
            </form>

            <section class="panel app-confirmation__card" id="confirmation-main-contact" aria-labelledby="confirmation-contact-title">
                <div class="app-confirmation__card-heading">
                    <h2 id="confirmation-contact-title">Основной контакт</h2>
                    <span class="app-confirmation__state {{ $primaryVerifiedContact ? 'app-confirmation__state--done' : '' }}">
                        {{ $primaryVerifiedContact ? 'Выполнено' : 'Обязательно' }}
                    </span>
                </div>
                <p>Достаточно одного подтверждённого <strong>основного</strong> контакта: Email, Telegram или другого поддерживаемого канала.</p>
                @if ($primaryVerifiedContact)
                    <p class="app-confirmation__value">{{ $primaryVerifiedContact->type->label() }}: {{ $primaryVerifiedContact->displayValue() }}</p>
                @else
                    @if ($primaryContact)
                        <p class="app-confirmation__value">{{ $primaryContact->type->label() }}: {{ $primaryContact->displayValue() }} — не подтверждён</p>
                    @endif
                    @include('theme::pages.account.partials.confirmation-contact-dialog')
                @endif
                <a href="{{ route('account.contacts') }}" class="app-confirmation__contact-link">Управлять контактами</a>
                @error('contact') <p class="app-confirmation__error" role="alert">{{ $message }}</p> @enderror
            </section>

            <div class="app-confirmation__finish">
                <p data-confirmation-requirement>
                    @if ($missingTotal)
                        Для подтверждения аккаунта необходимо
                        @if ($firstMissing)
                            <a href="#{{ $firstMissing['id'] }}" data-confirmation-missing-link>заполнить {{ mb_strtolower($firstMissing['label']) }}</a>
                        @else
                            <a href="#confirmation-main-contact" data-confirmation-missing-link>подтвердить основной контакт</a>
                        @endif
                        @if($missingTotal > 1) и ещё {{ $missingTotal - 1 }}@endif.
                    @else
                        Все обязательные данные заполнены. Теперь можно подтвердить аккаунт.
                    @endif
                </p>
                <button class="button primary" type="submit" form="confirmation-complete-form"
                        data-confirmation-submit @if($missingTotal) disabled @endif>Подтвердить аккаунт</button>
            </div>
        @endif
    </div>
@endsection
