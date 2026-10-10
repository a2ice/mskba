@extends('theme::layouts.account', ['title' => 'Профиль'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Профиль',
        'subtitle' => 'Управляй фотографией, публичным никнеймом и личными данными.',
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
            <form method="POST" action="{{ route('account.profile.update') }}" class="app-profile__personal-form">
                @csrf
                @method('PATCH')
                <div class="app-profile__field-grid">
                    <div class="field-group">
                        <label for="app-profile-first-name">Имя</label>
                        <input id="app-profile-first-name" type="text" maxlength="255"
                               @if (! $profileConfirmed) name="first_name" @endif
                               value="{{ $profileConfirmed ? $profile?->first_name : old('first_name', $profile?->first_name) }}"
                               @readonly($profileConfirmed)>
                        @error('first_name') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                    <div class="field-group">
                        <label for="app-profile-last-name">Фамилия</label>
                        <input id="app-profile-last-name" type="text" maxlength="255"
                               @if (! $profileConfirmed) name="last_name" @endif
                               value="{{ $profileConfirmed ? $profile?->last_name : old('last_name', $profile?->last_name) }}"
                               @readonly($profileConfirmed)>
                        @error('last_name') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                    <div class="field-group">
                        <label for="app-profile-middle-name">Отчество</label>
                        <input id="app-profile-middle-name" type="text" name="middle_name" maxlength="255"
                               value="{{ old('middle_name', $profile?->middle_name) }}">
                        @error('middle_name') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                    <div class="field-group">
                        <label for="app-profile-birth-date">Дата рождения</label>
                        <input id="app-profile-birth-date" type="date" max="{{ now()->subDay()->toDateString() }}"
                               @if (! $profileConfirmed) name="birth_date" @endif
                               value="{{ $profileConfirmed ? $profile?->birth_date?->toDateString() : old('birth_date', $profile?->birth_date?->toDateString()) }}"
                               @readonly($profileConfirmed)>
                        @if ($profile?->age !== null)
                            <small>{{ $profile->age }} лет</small>
                        @endif
                        @error('birth_date') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                    <div class="field-group">
                        <label for="app-profile-gender">Пол</label>
                        <span class="select-control">
                            <select id="app-profile-gender" @if (! $profileConfirmed) name="gender" @endif
                                    @disabled($profileConfirmed)>
                                <option value="">Не указан</option>
                                @foreach ($genders as $gender)
                                    <option value="{{ $gender->value }}" @selected(old('gender', $profile?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                                @endforeach
                            </select>
                            <svg width="20" height="20" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2" fill="none"
                                 stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
                        </span>
                        @error('gender') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                </div>
                <div class="app-profile__form-actions">
                    <button type="submit" class="button primary">Сохранить данные</button>
                    @if ($profileConfirmed)
                        <button type="button" class="button secondary" data-profile-change-request-open>Запросить изменение подтверждённых данных</button>
                    @endif
                </div>
            </form>
        </section>
    </div>

    @if ($profileConfirmed)
        <dialog class="mskba-modal app-profile__request-dialog" data-profile-change-request-dialog
                aria-labelledby="app-profile-request-heading">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-profile-request-heading" tabindex="-1">Изменение подтверждённых данных</h2>
                <button type="button" class="app-profile__dialog-close" data-profile-change-request-close aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-profile__request-body">
                <p>Изменение имени, фамилии, даты рождения или пола подтверждённого аккаунта будет доступно по заявке.</p>
                <p>Приём и обработка заявок пока не подключены. Твои текущие данные останутся без изменений.</p>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer">
                <button class="button secondary" type="button" data-profile-change-request-close>Понятно</button>
            </div>
        </dialog>
    @endif
    </div>
@endsection
