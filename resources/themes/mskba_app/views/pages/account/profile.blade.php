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
                                   title="Загрузить аватар" data-profile-avatar-preview>
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
                            JPEG, PNG или WebP до 10 МБ. Можно хранить до трёх аватаров и переключаться между ними.
                        </p>
                        <span class="app-profile__upload-state" role="status"
                              data-profile-avatar-upload-status aria-live="polite" hidden>Загружаем…</span>
                    </div>
                </div>
                <div class="app-profile__avatar-library" data-profile-avatar-library aria-label="Сохранённые аватары" @if ($avatars->isEmpty()) hidden @endif>
                        @foreach ($avatars as $avatar)
                            <div class="app-profile__avatar-item">
                                <img src="{{ $avatar->publicUrl() }}" alt="Аватар {{ $loop->iteration }}" width="76" height="76">
                                @if ($avatar->is_featured)
                                    <span class="app-profile__avatar-current">Основной</span>
                                @else
                                    <form method="POST" action="{{ route('account.avatar.activate', $avatar->id) }}" data-profile-avatar-action>
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="app-profile__text-action">Сделать основным</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('account.avatar.destroy', $avatar->id) }}" data-profile-avatar-action>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="app-profile__text-action app-profile__text-action--muted"
                                            aria-label="Удалить аватар {{ $loop->iteration }}">Удалить</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
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

        @include('theme::pages.account.partials.profile-personal-editor')
    </div>

    @include('theme::pages.account.partials.profile-change-request-dialog')
    </div>
@endsection
