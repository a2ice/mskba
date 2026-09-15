@php
    use App\Modules\Identity\Domain\Enums\UserGenderEnum;

    $initialPersona = old('onboarding_persona', $selectedPersona ?? '');
    $campaignVenue = $campaign?->venue;
    $authRedirectTo = route('acquisition.success', [], false);
@endphp

@extends('theme::layouts.app', [
    'title' => 'Стать участником MSKBA',
    'metaDescription' => 'Присоединяйтесь к MSKBA: находите игры, команды, тренировки и площадки, создавайте секции и баскетбольные мероприятия.',
])

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/acquisition-onboarding.css') }}">
@endsection

@section('content')
    <section
        class="acquisition-onboarding"
        data-acquisition-onboarding
        data-persona-url="{{ route('acquisition.persona') }}"
        data-location-url="{{ route('acquisition.location') }}"
        data-success-url="{{ route('acquisition.success') }}"
        data-authenticated="{{ auth()->check() ? '1' : '0' }}"
        data-initial-persona="{{ $initialPersona }}"
        data-has-location-target="{{ $campaignVenue?->location?->address?->latitude !== null && $campaignVenue?->location?->address?->longitude !== null ? '1' : '0' }}"
    >
        <div class="acquisition-onboarding__glow acquisition-onboarding__glow--one" aria-hidden="true"></div>
        <div class="acquisition-onboarding__glow acquisition-onboarding__glow--two" aria-hidden="true"></div>

        <div class="inner acquisition-onboarding__inner">
            <header class="acquisition-onboarding__hero">
                <div class="acquisition-onboarding__hero-copy">
                    <span class="acquisition-onboarding__eyebrow">
                        @if($campaignVenue)
                            MSKBA · {{ $campaignVenue->name }}
                        @else
                            Московская баскетбольная ассоциация
                        @endif
                    </span>
                    <h1>Твоя баскетбольная Москва начинается здесь</h1>
                    <p>
                        Найди игру на вечер, собери команду, открой секцию, добавь площадку или проведи своё мероприятие.
                        Один аккаунт — все баскетбольные сценарии MSKBA.
                    </p>

                    @guest
                        <button type="button" class="acquisition-onboarding__login-link" data-acquisition-show-login>
                            <i class="ti ti-login-2" aria-hidden="true"></i>
                            У меня уже есть аккаунт
                        </button>
                    @else
                        <div class="acquisition-onboarding__signed-in">
                            <i class="ti ti-circle-check" aria-hidden="true"></i>
                            Вы уже вошли как <strong>{{ auth()->user()->canonical()->username }}</strong>
                        </div>
                    @endguest
                </div>

                <div class="acquisition-onboarding__hero-mark" aria-hidden="true">
                    <img src="{{ asset('images/logo-header-cropped.png') }}" alt="" width="420" height="165">
                    <span>Играй · создавай · объединяй</span>
                </div>
            </header>

            @if($campaignVenue)
                <div class="acquisition-onboarding__venue-context">
                    <div>
                        <span class="acquisition-onboarding__venue-kicker">Этот вход связан с площадкой</span>
                        <strong>{{ $campaignVenue->name }}</strong>
                    </div>
                    <button type="button" class="btn btn--secondary-bordered btn--sm" data-acquisition-location-button>
                        <i class="ti ti-current-location" aria-hidden="true"></i>
                        Подтвердить, что я здесь
                    </button>
                    <span class="acquisition-onboarding__location-status" data-acquisition-location-status aria-live="polite"></span>
                </div>
            @endif

            @guest
                <div class="acquisition-onboarding__login-panel" data-acquisition-login-panel @unless($errors->has('login')) hidden @endunless>
                    <div class="acquisition-onboarding__panel-heading">
                        <div>
                            <span class="acquisition-onboarding__step-label">Уже с нами?</span>
                            <h2>Войдите в аккаунт</h2>
                        </div>
                        <button type="button" class="acquisition-onboarding__panel-close" data-acquisition-hide-login aria-label="Закрыть форму входа">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </button>
                    </div>
                    @include('theme::partials.auth.inline-login', ['authRedirectTo' => $authRedirectTo])
                </div>
            @endguest

            <div class="acquisition-onboarding__wizard-shell">
                @if($errors->any() && ! $errors->has('login'))
                    <div class="alert alert-danger acquisition-onboarding__errors">
                        <strong>Проверьте данные регистрации.</strong>
                        <ul class="mb-0 mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="acquisition-onboarding__progress" aria-live="polite">
                    <div>
                        <span>Шаг <strong data-acquisition-progress-current>1</strong></span>
                        <span data-acquisition-progress-caption>Выберите свой сценарий</span>
                    </div>
                    <div class="acquisition-onboarding__progress-track" aria-hidden="true">
                        <span data-acquisition-progress-bar></span>
                    </div>
                </div>

                <form method="POST" action="{{ route('auth.register') }}" data-acquisition-form novalidate>
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ $authRedirectTo }}">
                    <input type="hidden" name="role" value="{{ old('role') }}" data-acquisition-role>

                    <section class="acquisition-onboarding__step" data-acquisition-step="persona">
                        <div class="acquisition-onboarding__step-heading">
                            <span class="acquisition-onboarding__step-label">Начнём с главного</span>
                            <h2>Что вы хотите делать в MSKBA?</h2>
                            <p>Это не навсегда и не ограничивает аккаунт. Выберите сценарий, с которого удобнее начать.</p>
                        </div>

                        <div class="acquisition-onboarding__personas">
                            <label class="acquisition-persona-card">
                                <input type="radio" name="onboarding_persona" value="player" @checked($initialPersona === 'player')>
                                <span class="acquisition-persona-card__icon"><i class="ti ti-ball-basketball" aria-hidden="true"></i></span>
                                <span class="acquisition-persona-card__body">
                                    <strong>Я игрок</strong>
                                    <span>Хочу играть чаще: находить игры и тренировки, участвовать в турнирах, создавать команды и присоединяться к другим.</span>
                                </span>
                                <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                            </label>

                            <label class="acquisition-persona-card">
                                <input type="radio" name="onboarding_persona" value="coach" @checked($initialPersona === 'coach')>
                                <span class="acquisition-persona-card__icon"><i class="ti ti-whistle" aria-hidden="true"></i></span>
                                <span class="acquisition-persona-card__body">
                                    <strong>Я тренер</strong>
                                    <span>Хочу открыть секцию, набирать игроков подходящего возраста и уровня, планировать занятия и развивать команду.</span>
                                </span>
                                <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                            </label>

                            <label class="acquisition-persona-card">
                                <input type="radio" name="onboarding_persona" value="venue" @checked($initialPersona === 'venue')>
                                <span class="acquisition-persona-card__icon"><i class="ti ti-building-stadium" aria-hidden="true"></i></span>
                                <span class="acquisition-persona-card__body">
                                    <strong>Я представляю площадку</strong>
                                    <span>Хочу добавить площадку, публиковать условия и расписание, принимать заявки на аренду и находить новых клиентов.</span>
                                </span>
                                <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                            </label>

                            <label class="acquisition-persona-card">
                                <input type="radio" name="onboarding_persona" value="organizer" @checked($initialPersona === 'organizer')>
                                <span class="acquisition-persona-card__icon"><i class="ti ti-calendar-event" aria-hidden="true"></i></span>
                                <span class="acquisition-persona-card__body">
                                    <strong>Я организатор</strong>
                                    <span>Хочу проводить игры, тренировки и турниры, собирать участников, бронировать площадки и вести событие в одном месте.</span>
                                </span>
                                <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                            </label>

                            <label class="acquisition-persona-card acquisition-persona-card--quiet">
                                <input type="radio" name="onboarding_persona" value="explore" @checked($initialPersona === 'explore')>
                                <span class="acquisition-persona-card__icon"><i class="ti ti-compass" aria-hidden="true"></i></span>
                                <span class="acquisition-persona-card__body">
                                    <strong>Пока просто посмотрю</strong>
                                    <span>Создам аккаунт без роли, осмотрюсь на портале и выберу направления позже.</span>
                                </span>
                                <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                            </label>
                        </div>
                    </section>

                    @guest
                        <section class="acquisition-onboarding__step" data-acquisition-step="account" hidden>
                            <div class="acquisition-onboarding__step-heading">
                                <span class="acquisition-onboarding__step-label">Ваш аккаунт</span>
                                <h2>Создайте вход в MSKBA</h2>
                                <p>Минимум данных сейчас. Контакт и подтверждение аккаунта можно пройти следующим шагом уже внутри портала.</p>
                            </div>

                            <div class="acquisition-onboarding__form-grid">
                                <div class="field acquisition-onboarding__field acquisition-onboarding__field--wide">
                                    <label for="acquisitionUsername" class="form-label">Логин</label>
                                    <input
                                        id="acquisitionUsername"
                                        type="text"
                                        name="username"
                                        value="{{ old('username') }}"
                                        class="form-control @error('username') is-invalid @enderror"
                                        autocomplete="username"
                                        minlength="3"
                                        required
                                    >
                                    <small>Используется для входа. Публичный никнейм можно настроить отдельно в аккаунте.</small>
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionPassword" class="form-label">Пароль</label>
                                    <input id="acquisitionPassword" type="password" name="password" class="form-control" autocomplete="new-password" required>
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionPasswordConfirmation" class="form-label">Повторите пароль</label>
                                    <input id="acquisitionPasswordConfirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password" required>
                                </div>
                            </div>

                            <label class="privacy-consent acquisition-onboarding__consent">
                                <input class="privacy-consent__input" type="checkbox" name="privacy_consent" value="1" @checked(old('privacy_consent')) required>
                                <span class="privacy-consent__control" aria-hidden="true"></span>
                                <span class="privacy-consent__text">
                                    Я даю согласие на обработку персональных данных и принимаю условия
                                    <a href="{{ route('privacy.policy') }}" target="_blank" rel="noopener">Политики обработки персональных данных</a>.
                                </span>
                            </label>
                        </section>

                        <section class="acquisition-onboarding__step" data-acquisition-step="profile" hidden>
                            <div class="acquisition-onboarding__step-heading">
                                <span class="acquisition-onboarding__step-label">Базовый профиль</span>
                                <h2>Пара деталей для баскетбольного профиля</h2>
                                <p>Для игрока и тренера дата рождения и пол нужны для корректной работы ролевых функций. Имя можно заполнить сейчас или позже.</p>
                            </div>

                            <div class="acquisition-onboarding__form-grid">
                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionFirstName" class="form-label">Имя</label>
                                    <input id="acquisitionFirstName" type="text" name="first_name" value="{{ old('first_name') }}" class="form-control" autocomplete="given-name">
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionLastName" class="form-label">Фамилия</label>
                                    <input id="acquisitionLastName" type="text" name="last_name" value="{{ old('last_name') }}" class="form-control" autocomplete="family-name">
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionBirthDate" class="form-label">Дата рождения</label>
                                    <input id="acquisitionBirthDate" type="date" name="birth_date" value="{{ old('birth_date') }}" class="form-control" data-acquisition-profile-required>
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionGender" class="form-label">Пол</label>
                                    <select id="acquisitionGender" name="gender" class="form-select" data-acquisition-profile-required>
                                        <option value="">Выберите</option>
                                        @foreach(UserGenderEnum::cases() as $gender)
                                            <option value="{{ $gender->value }}" @selected(old('gender') === $gender->value)>{{ $gender->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </section>
                    @endguest

                    <footer class="acquisition-onboarding__wizard-footer">
                        <button type="button" class="btn btn--secondary-bordered btn--sm" data-acquisition-back hidden>
                            <i class="ti ti-arrow-left" aria-hidden="true"></i>
                            Назад
                        </button>
                        <span class="acquisition-onboarding__wizard-spacer"></span>
                        <button type="button" class="btn btn--primary btn--sm" data-acquisition-next disabled>
                            Продолжить
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button type="submit" class="btn btn--primary btn--sm" data-acquisition-submit hidden>
                            Зарегистрироваться
                            <i class="ti ti-check" aria-hidden="true"></i>
                        </button>
                        @auth
                            <button type="button" class="btn btn--primary btn--sm" data-acquisition-authenticated-continue hidden>
                                Продолжить
                                <i class="ti ti-arrow-right" aria-hidden="true"></i>
                            </button>
                        @endauth
                    </footer>
                </form>
            </div>

            <div class="acquisition-onboarding__trust">
                <span><i class="ti ti-lock" aria-hidden="true"></i> Регистрация бесплатна</span>
                <span><i class="ti ti-switch-horizontal" aria-hidden="true"></i> Роли можно менять и добавлять</span>
                <span><i class="ti ti-map-pin" aria-hidden="true"></i> Геопроверка QR добровольна</span>
            </div>
        </div>
    </section>

    <script src="{{ asset('js/acquisition-onboarding.js') }}" defer></script>
@endsection
