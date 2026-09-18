@php
    $title = $isFirstSetup ? 'Настройка публичности' : 'Публичность профиля';
    $selected = collect($selectedTypeValues ?? []);
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen px-1">
        <div class="inner">
            <div class="section-heading">
                <span class="text-muted">{{ $isFirstSetup ? 'Последний шаг регистрации' : 'Настройки аккаунта' }}</span>
                <h1 class="mb-3">{{ $title }}</h1>
                <p class="mb-2">
                    Выберите, какие данные MSKBA может показывать публично неограниченному кругу посетителей.
                    Любой пункт можно оставить закрытым.
                </p>
                <p class="text-muted mb-4">
                    Эти настройки можно изменить позже. Для публикации выбранных данных требуется отдельное согласие,
                    независимое от согласия на обычную обработку персональных данных.
                </p>
            </div>

            @if($errors->any())
                <div class="alert alert-danger mb-4">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('account.privacy.distribution.update') }}">
                @csrf
                @method('PUT')

                <section class="account-settings-card account-privacy mb-4" aria-labelledby="public-data-types-title">
                    <h2 id="public-data-types-title" class="h3 mb-2">Что будет видно публично</h2>
                    <p class="text-muted mb-4">
                        Для нового профиля мы предлагаем базовый публичный набор. Вы можете выключить любой тумблер до подтверждения.
                    </p>

                    @foreach($distributionTypes as $type)
                        @include('theme::partials.forms.toggle', [
                            'id' => 'public-data-'.$type->value,
                            'name' => 'public['.$type->value.']',
                            'checked' => $selected->contains($type->value),
                            'title' => $type->label(),
                            'description' => $type->description(),
                            'wrapperClass' => 'mb-3',
                        ])
                    @endforeach
                </section>

                <section class="account-settings-card mb-4" aria-labelledby="distribution-consent-title">
                    <h2 id="distribution-consent-title" class="h3 mb-3">Отдельное согласие на распространение</h2>

                    <label class="privacy-consent">
                        <input
                            class="privacy-consent__input"
                            type="checkbox"
                            name="distribution_consent"
                            value="1"
                            @checked(old('distribution_consent'))
                        >
                        <span class="privacy-consent__control" aria-hidden="true"></span>
                        <span class="privacy-consent__text">
                            Я разрешаю MSKBA распространять выбранные выше персональные данные в публичных разделах портала
                            на условиях
                            <a href="{{ route('personal-data.distribution-consent') }}" target="_blank" rel="noopener">
                                отдельного согласия на распространение персональных данных
                            </a>.
                        </span>
                    </label>

                    <p class="form-text mb-0 mt-2">
                        Если оставить все тумблеры выключенными, согласие не требуется. В любой момент можно вернуться на эту страницу,
                        сузить перечень или полностью отключить публичное распространение.
                    </p>

                    @error('distribution_consent')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </section>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" name="action" value="save" class="btn btn--primary btn--sm">
                        Сохранить и продолжить
                    </button>
                    <button type="submit" name="action" value="private" class="btn btn--secondary-bordered btn--sm">
                        {{ $isFirstSetup ? 'Продолжить без публикации' : 'Скрыть всё публичное' }}
                    </button>
                </div>

                @if($hasActiveConsent)
                    <p class="form-text mt-3 mb-0">
                        Сохранение нового перечня заменит действующее согласие новой версией выбора; предыдущая запись останется в истории как отозванная.
                    </p>
                @endif
            </form>
        </div>
    </section>
@endsection
