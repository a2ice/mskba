@extends('theme::layouts.account', ['title' => 'Профиль'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Профиль',
        'intro' => 'Управляй аватаром, никнеймом и личными данными.',
    ])
@endsection

@section('account-content')
    <div class="app-profile-page">
    <div class="app-profile" data-account-profile>
        @if (session('profile_status') || session('avatar_status'))
            <div class="notice app-profile__notice" role="status">{{ session('profile_status') ?: session('avatar_status') }}</div>
        @endif
        @if (session('avatar_error') || $errors->any())
            <div class="notice app-profile__notice app-profile__notice--error" role="alert">
                {{ session('avatar_error') ?: $errors->first() }}
            </div>
        @endif

        <div class="app-profile__primary-grid">
            <section class="panel app-profile__panel" aria-labelledby="app-profile-photo-heading">
                <h2 id="app-profile-photo-heading">Аватар</h2>
                <div class="app-profile__avatar-row">
                    @if ($profile && ! $user->isBlocked())
                        <form method="POST" action="{{ route('account.avatar.store') }}"
                              enctype="multipart/form-data" class="app-profile__avatar-upload"
                              data-profile-avatar-upload>
                            @csrf
                            <label for="app-profile-avatar-file"
                                   class="app-profile__avatar-preview app-profile__avatar-upload-target"
                                   title="Загрузить аватар">
                                @if ($profile->avatarUrl())
                                    <img src="{{ $profile->avatarUrl() }}" alt="Твой текущий аватар" width="112" height="112">
                                @else
                                    <svg class="app-profile__avatar-placeholder" width="48" height="48"
                                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                         fill="none" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true" focusable="false"><use href="#user"/></svg>
                                @endif
                            </label>
                            <input type="file" id="app-profile-avatar-file" name="avatar"
                                   accept="image/jpeg,image/png,image/webp"
                                   aria-label="Загрузить аватар">
                        </form>
                    @else
                        <div class="app-profile__avatar-preview" aria-label="Аватар не загружен">
                            <svg class="app-profile__avatar-placeholder" width="48" height="48"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                 fill="none" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true" focusable="false"><use href="#user"/></svg>
                        </div>
                    @endif
                    <div class="app-profile__avatar-intro">
                        <strong>Фотография профиля</strong>
                        <p>
                            @if ($profile && ! $user->isBlocked())
                                Нажми на аватар, чтобы загрузить фотографию.
                            @else
                                Сначала сохрани личные данные — после этого можно будет загрузить аватар.
                            @endif
                            JPEG, PNG или WebP до 5 МБ. Можно хранить до трёх аватаров и переключаться между ними.
                        </p>
                        <span class="app-profile__upload-state" role="status"
                              data-profile-avatar-upload-status hidden>Загружаем…</span>
                    </div>
                </div>
                @if ($avatars->isNotEmpty())
                    <div class="app-profile__avatar-library" aria-label="Сохранённые аватары">
                        @foreach ($avatars as $avatar)
                            <div class="app-profile__avatar-item">
                                <img src="{{ $avatar->publicUrl() }}" alt="Аватар {{ $loop->iteration }}" width="76" height="76">
                                @if ($avatar->is_featured)
                                    <span class="app-profile__avatar-current">Основной</span>
                                @else
                                    <form method="POST" action="{{ route('account.avatar.activate', $avatar->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="app-profile__text-action">Сделать основным</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('account.avatar.destroy', $avatar->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="app-profile__text-action app-profile__text-action--muted"
                                            aria-label="Удалить аватар {{ $loop->iteration }}">Удалить</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="panel app-profile__panel" aria-labelledby="app-profile-address-heading">
                <h2 id="app-profile-address-heading">Никнейм и ссылка на профиль</h2>
                <form method="POST" action="{{ route('account.nickname.update') }}"
                      class="app-profile__nickname-form field-group" data-profile-nickname-form>
                    @csrf
                    @method('PATCH')
                    <label for="app-profile-nickname">Публичный никнейм</label>
                    <div class="app-profile__nickname-line">
                        <input id="app-profile-nickname" name="nickname" type="text"
                               value="{{ $user->nickname }}" maxlength="30" minlength="3"
                               pattern="[a-zA-Z][a-zA-Z0-9_]{2,29}" autocomplete="off"
                               spellcheck="false" placeholder="{{ $nicknameSuggestion }}"
                               aria-describedby="app-profile-nickname-help app-profile-nickname-feedback">
                        <button type="submit" class="button secondary">Сохранить</button>
                    </div>
                    <small id="app-profile-nickname-help">Никнейм может использоваться в ссылке на твою публичную страницу. От 3 до 30 символов: первая — латинская буква, далее буквы, цифры и _. Никнейм должен быть свободен.</small>
                    <span id="app-profile-nickname-feedback" role="status" aria-live="polite"
                          data-profile-nickname-feedback></span>
                </form>
                <div class="app-profile__public-url">
                    <span>Ссылка на профиль</span>
                    <a href="{{ $publicProfileUrl }}" data-profile-public-url>{{ $publicProfileUrl }}</a>
                </div>
            </section>

        </div>

        <section class="panel app-profile__panel" aria-labelledby="app-profile-personal-heading">
            <h2 id="app-profile-personal-heading">Личные данные</h2>
            @if ($profileConfirmed)
                <p class="app-profile__support-copy">Твои имя, фамилия, дата рождения и пол уже подтверждены. Для их изменения нужно отправить заявку.</p>
            @endif
            @php
                $personalFields = [
                    ['name' => 'first_name', 'id' => 'app-profile-first-name', 'label' => 'Имя', 'type' => 'text',
                     'auto' => 'given-name', 'value' => (string) ($profile?->first_name ?? '')],
                    ['name' => 'last_name', 'id' => 'app-profile-last-name', 'label' => 'Фамилия', 'type' => 'text',
                     'auto' => 'family-name', 'value' => (string) ($profile?->last_name ?? '')],
                    ['name' => 'middle_name', 'id' => 'app-profile-middle-name', 'label' => 'Отчество', 'type' => 'text',
                     'auto' => 'additional-name', 'value' => (string) ($profile?->middle_name ?? '')],
                    ['name' => 'birth_date', 'id' => 'app-profile-birth-date', 'label' => 'Дата рождения', 'type' => 'date',
                     'auto' => 'bday', 'value' => $profile?->birth_date?->toDateString() ?? ''],
                    ['name' => 'gender', 'id' => 'app-profile-gender', 'label' => 'Пол', 'type' => 'select',
                     'auto' => 'sex', 'value' => $profile?->gender?->value ?? ''],
                ];
            @endphp
            <form method="POST" action="{{ route('account.profile.update') }}"
                  class="app-profile__personal-form" data-profile-personal-form
                  data-save-url="{{ route('account.profile.update') }}">
                @csrf
                @method('PATCH')
                <div class="app-profile__field-grid">
                    @foreach ($personalFields as $field)
                        @php($locked = $profileConfirmed && $field['name'] !== 'middle_name')
                        <div class="field-group" @unless($locked)
                             data-inline-profile-field data-field-name="{{ $field['name'] }}"
                             data-saved-value="{{ $field['value'] }}"
                             @endunless>
                            <label for="{{ $field['id'] }}">{{ $field['label'] }}</label>
                            <div class="app-confirmation__input-row">
                                <span class="app-confirmation__input-wrap {{ $field['type'] === 'select' ? 'select-control' : '' }} {{ $field['type'] === 'date' ? 'app-confirmation__input-wrap--date' : '' }} {{ $locked ? 'app-profile__locked-wrap' : '' }}">
                                    @if ($field['type'] === 'select')
                                        <select id="{{ $field['id'] }}" autocomplete="{{ $field['auto'] }}"
                                                @unless($locked) name="{{ $field['name'] }}" data-inline-profile-input @endunless
                                                @disabled($locked)>
                                            <option value="">Не указан</option>
                                            @foreach ($genders as $gender)
                                                <option value="{{ $gender->value }}" @selected(old($field['name'], $field['value']) === $gender->value)>{{ $gender->label() }}</option>
                                            @endforeach
                                        </select>
                                        <svg class="app-confirmation__select-arrow" aria-hidden="true"><use href="#chevron-down"/></svg>
                                    @else
                                        <input id="{{ $field['id'] }}" type="{{ $field['type'] }}"
                                               autocomplete="{{ $field['auto'] }}"
                                               @if ($field['type'] === 'text') maxlength="255" @endif
                                               @if ($field['type'] === 'date') max="{{ now()->subDay()->toDateString() }}" @endif
                                               @unless($locked) name="{{ $field['name'] }}" data-inline-profile-input @endunless
                                               value="{{ $locked ? $field['value'] : old($field['name'], $field['value']) }}"
                                               @readonly($locked)>
                                    @endif
                                    @unless($locked)
                                        <button type="button" class="app-confirmation__save-field"
                                                data-inline-profile-save
                                                aria-label="Сохранить {{ mb_strtolower($field['label']) }}"
                                                title="Сохранить {{ mb_strtolower($field['label']) }}"
                                                disabled>
                                            <svg class="app-confirmation__save-icon" aria-hidden="true"><use href="#save"/></svg>
                                            <span class="app-confirmation__save-loader" data-inline-profile-loading aria-hidden="true" hidden></span>
                                            <span class="app-confirmation__save-cooldown" data-inline-profile-cooldown aria-hidden="true" hidden></span>
                                        </button>
                                    @endunless
                                </span>
                            </div>
                            @unless($locked)
                                <small class="app-profile__error" data-inline-profile-error role="alert" hidden></small>
                            @endunless
                            @error($field['name']) <small class="app-profile__error">{{ $message }}</small> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="app-profile__form-actions">
                    <button type="submit" class="button primary" data-inline-profile-save-all disabled>Сохранить всё</button>
                    @if ($profileConfirmed && $activeProfileChangeRequest === null)
                        <button type="button" class="button secondary" data-profile-change-request-open>Запросить изменение подтверждённых данных</button>
                    @endif
                    <p class="app-profile__save-feedback" data-inline-profile-feedback role="status" aria-live="polite"></p>
                </div>
            </form>
        </section>

    @if ($profileConfirmed && $latestProfileChangeRequest)
        <section class="panel app-profile__panel" aria-labelledby="profile-request-status">
            <h2 id="profile-request-status">Заявка на изменение данных №{{ $latestProfileChangeRequest->id }}</h2>
            <p class="app-profile__support-copy">
                Статус: <strong>{{ [
                    'pending' => 'На рассмотрении',
                    'approved' => 'Одобрена',
                    'rejected' => 'Отклонена',
                    'cancelled' => 'Отменена',
                    'blocked' => 'Заблокирована',
                ][$latestProfileChangeRequest->status] ?? $latestProfileChangeRequest->status }}</strong>.
                @if ($latestProfileChangeRequest->review_reason)
                    Причина решения: {{ $latestProfileChangeRequest->review_reason }}
                @endif
                @if ($latestProfileChangeRequest->status === 'blocked')
                    Повторная подача недоступна до разблокировки администратором.
                @elseif ($latestProfileChangeRequest->status === 'rejected')
                    Ты можешь отправить новую заявку.
                @endif
            </p>
            <a class="button secondary" href="{{ route('account.notifications.threads.profile', $latestProfileChangeRequest) }}">Открыть обсуждение</a>
            @if ($latestProfileChangeRequest->status === 'pending')
                <form method="POST" action="{{ route('account.profile-change-requests.cancel', $latestProfileChangeRequest) }}">
                    @csrf
                    <button type="submit" class="button secondary">Отменить заявку</button>
                </form>
            @endif
        </section>
    @endif
    </div>

    @if ($profileConfirmed && $activeProfileChangeRequest === null)
        <dialog class="mskba-modal app-profile__request-dialog" data-profile-change-request-dialog
                aria-labelledby="app-profile-request-heading">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-profile-request-heading" tabindex="-1">Изменение данных</h2>
                <button type="button" class="app-profile__dialog-close" data-profile-change-request-close aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-profile__request-body">
                <p>Укажи исправленные данные и причину. Пока модератор не одобрит заявку, профиль останется без изменений.</p>
                <form method="POST" action="{{ route('account.profile-change-requests.store') }}" id="profile-change-request-form" class="app-profile__personal-form">
                    @csrf
                    <div class="app-profile__field-grid">
                        <div class="field-group">
                            <label for="profile-change-first">Имя</label>
                            <input id="profile-change-first" name="first_name" required maxlength="255" value="{{ old('first_name', $profile?->first_name) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-last">Фамилия</label>
                            <input id="profile-change-last" name="last_name" required maxlength="255" value="{{ old('last_name', $profile?->last_name) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-birth">Дата рождения</label>
                            <input id="profile-change-birth" type="date" name="birth_date" required max="{{ now()->subDay()->toDateString() }}"
                                   value="{{ old('birth_date', $profile?->birth_date?->toDateString()) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-gender">Пол</label>
                            <span class="select-control">
                                <select id="profile-change-gender" name="gender" required>
                                    @foreach ($genders as $gender)
                                        <option value="{{ $gender->value }}" @selected(old('gender', $profile?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                                    @endforeach
                                </select>
                                <svg class="app-confirmation__select-arrow" aria-hidden="true"><use href="#chevron-down"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="profile-change-reason">Причина изменения</label>
                        <textarea id="profile-change-reason" name="reason" required minlength="10" maxlength="2000" rows="3">{{ old('reason') }}</textarea>
                        @error('reason') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                </form>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer">
                <button class="button secondary" type="button" data-profile-change-request-close>Отмена</button>
                <button type="submit" form="profile-change-request-form" class="button primary">Отправить заявку</button>
            </div>
        </dialog>
    @endif
    </div>
@endsection
