@php
    use App\Modules\Identity\Domain\Enums\UserGenderEnum;

    $initialPersona = old('onboarding_persona', $selectedPersona ?? '');
    $campaignVenue = $campaign?->venue;
    $canVerifyCampaignLocation = (bool) ($campaign?->location_verification_enabled)
        && $campaignVenue?->location?->address?->latitude !== null
        && $campaignVenue?->location?->address?->longitude !== null;
    $isAuthenticatedOnboarding = isset($authenticatedUser) && $authenticatedUser !== null;
    $loginRedirectParams = ['resume' => 1];
    if ($campaign?->public_code) {
        $loginRedirectParams['campaignCode'] = $campaign->public_code;
    }
    $loginRedirectTo = route('acquisition.join', $loginRedirectParams, false);
    $registrationRedirectTo = route('acquisition.success', [], false);
    $hasLoginErrors = $errors->has('login') || session()->has('error');
    $hasRegistrationErrors = $errors->any() && ! $hasLoginErrors;
    $initialFlow = $hasLoginErrors ? 'login' : ($hasRegistrationErrors ? 'join' : '');
    $errorStep = $hasRegistrationErrors ? 'account' : '';
    $activeRoleValues = $activeRoleValues ?? [];
    $activeRoleLabels = collect($participationRoles ?? [])
        ->filter(fn ($role): bool => in_array($role->value, $activeRoleValues, true))
        ->map(fn ($role): string => mb_strtolower($role->label()))
        ->values();
    $roleSummary = match ($activeRoleLabels->count()) {
        0 => 'не установлена',
        1 => $activeRoleLabels->first(),
        2 => $activeRoleLabels->first().' и '.$activeRoleLabels->get(1),
        default => $activeRoleLabels->first().' и ещё '.($activeRoleLabels->count() - 1),
    };
@endphp

@extends('theme::layouts.app', [
    'title' => 'Добро пожаловать в MSKBA',
    'metaDescription' => 'Присоединяйтесь к MSKBA: выберите роль, создайте аккаунт и начните пользоваться баскетбольным порталом.',
])

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/acquisition-onboarding.css') }}">
    <link rel="stylesheet" href="{{ asset('css/acquisition-onboarding-v2.css') }}">
@endsection

@section('content')
    <section
        class="acquisition-onboarding"
        data-acquisition-onboarding
        data-persona-url="{{ route('acquisition.persona') }}"
        data-location-url="{{ route('acquisition.location') }}"
        data-roles-url="{{ route('acquisition.roles.update') }}"
        data-success-url="{{ route('acquisition.success') }}"
        data-authenticated="{{ $isAuthenticatedOnboarding ? '1' : '0' }}"
        data-initial-persona="{{ $initialPersona }}"
        data-initial-flow="{{ $isAuthenticatedOnboarding ? 'authenticated' : $initialFlow }}"
        data-error-step="{{ $errorStep }}"
        data-has-location-target="{{ $canVerifyCampaignLocation ? '1' : '0' }}"
    >
        <div class="acquisition-onboarding__glow acquisition-onboarding__glow--one" aria-hidden="true"></div>
        <div class="acquisition-onboarding__glow acquisition-onboarding__glow--two" aria-hidden="true"></div>

        <div class="inner acquisition-onboarding__inner">
            <div class="acquisition-onboarding__wizard-shell">
                @if($hasRegistrationErrors && ! $isAuthenticatedOnboarding)
                    <div class="alert alert-danger acquisition-onboarding__errors">
                        <strong>Проверь данные регистрации.</strong>
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
                        <span data-acquisition-progress-caption>{{ $isAuthenticatedOnboarding ? 'Твои роли' : 'Быстрая регистрация' }}</span>
                    </div>
                    <div class="acquisition-onboarding__progress-track" aria-hidden="true">
                        <span data-acquisition-progress-bar></span>
                    </div>
                </div>

                <section class="acquisition-onboarding__step acquisition-onboarding__step--entry" data-acquisition-step="entry">
                    @if($isAuthenticatedOnboarding)
                        <div class="acquisition-onboarding__entry-copy">
                            <span class="acquisition-onboarding__step-label">С возвращением</span>
                            <h1>Привет! Рады видеть тебя снова.</h1>
                            <p>
                                Ты уже вошёл в аккаунт. Проверь свои роли на портале: можно оставить всё как есть,
                                добавить новые или отключить ненужные.
                            </p>
                        </div>

                        <form class="acquisition-onboarding__roles-form" data-acquisition-auth-roles-form novalidate>
                            @csrf
                            <details class="acquisition-onboarding__roles" data-acquisition-roles-details @if(empty($activeRoleValues)) open @endif>
                                <summary class="acquisition-onboarding__roles-summary">
                                    <span>
                                        <small>Моя роль</small>
                                        <strong data-acquisition-role-summary>{{ $roleSummary }}</strong>
                                    </span>
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </summary>

                                <div class="acquisition-onboarding__roles-body">
                                    <p>Можно выбрать несколько ролей. Это влияет на профиль и на то, какие действия мы предложим дальше.</p>

                                    <div class="acquisition-onboarding__role-grid">
                                        @foreach($participationRoles as $role)
                                            @include('theme::partials.forms.toggle', [
                                                'id' => 'acquisition-role-'.$role->value,
                                                'name' => 'roles['.$role->value.']',
                                                'checked' => in_array($role->value, $activeRoleValues, true),
                                                'title' => $role->label(),
                                                'description' => $role->description(),
                                                'wrapperClass' => 'acquisition-onboarding__role-toggle',
                                                'inputAttributes' => [
                                                    'data-acquisition-role-toggle' => true,
                                                    'data-role-label' => $role->label(),
                                                ],
                                            ])
                                        @endforeach
                                    </div>

                                    <div class="acquisition-onboarding__roles-actions">
                                        <button type="button" class="btn btn--secondary-bordered btn--sm" data-acquisition-save-roles>
                                            Сохранить роли
                                        </button>
                                        <span class="acquisition-onboarding__roles-status" data-acquisition-roles-status aria-live="polite"></span>
                                    </div>
                                </div>
                            </details>

                            <div class="acquisition-onboarding__entry-actions acquisition-onboarding__entry-actions--single">
                                <button type="button" class="btn btn--primary acquisition-onboarding__entry-button" data-acquisition-auth-continue>
                                    Продолжить
                                    <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="acquisition-onboarding__entry-copy">
                            <span class="acquisition-onboarding__step-label">Московская Баскетбольная Ассоциация</span>
                            <h1>Привет и добро пожаловать на MSKBA.</h1>
                            <p>
                                Ты можешь сразу выбрать свою роль на портале — или сделать это позже в любое время.
                                Если у тебя уже есть аккаунт, просто войди.
                            </p>
                        </div>

                        <div class="acquisition-onboarding__entry-actions">
                            <button type="button" class="btn btn--primary acquisition-onboarding__entry-button" data-acquisition-start-join>
                                Присоединиться
                                <i class="ti ti-arrow-right" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="acquisition-onboarding__entry-button acquisition-onboarding__entry-button--login" data-acquisition-start-login>
                                <i class="ti ti-login-2" aria-hidden="true"></i>
                                У меня уже есть аккаунт
                            </button>
                        </div>

                        <a href="#join-faq" class="acquisition-onboarding__login-link">
                            <i class="ti ti-help-circle" aria-hidden="true"></i>
                            Частые вопросы
                            <i class="ti ti-chevron-down" aria-hidden="true"></i>
                        </a>
                    @endif
                </section>

                @unless($isAuthenticatedOnboarding)
                    <section class="acquisition-onboarding__step acquisition-onboarding__step--login" data-acquisition-step="login" hidden>
                        <div class="acquisition-onboarding__step-heading">
                            <span class="acquisition-onboarding__step-label">С возвращением</span>
                            <h2>Войди в свой аккаунт</h2>
                            <p>После входа сможешь проверить свои роли и продолжить.</p>
                        </div>
                        @include('theme::partials.auth.inline-login', ['authRedirectTo' => $loginRedirectTo])
                    </section>

                    <form id="acquisitionRegistrationForm" method="POST" action="{{ route('auth.register') }}" data-acquisition-form novalidate>
                        @csrf
                        <input type="hidden" name="redirect_to" value="{{ $registrationRedirectTo }}">
                        <input type="hidden" name="role" value="{{ old('role') }}" data-acquisition-role>

                        <section class="acquisition-onboarding__step" data-acquisition-step="persona" hidden>
                            <div class="acquisition-onboarding__step-heading">
                                <span class="acquisition-onboarding__step-label">Твоя роль</span>
                                <h2>Кем ты хочешь быть на портале?</h2>
                                <p>Выбери роль, с которой хочешь начать. Это не окончательный выбор — позже ты сможешь изменить её или добавить другие роли.</p>
                            </div>

                            <div class="acquisition-onboarding__personas">
                                <label class="acquisition-persona-card">
                                    <input type="radio" name="onboarding_persona" value="player" @checked($initialPersona === 'player')>
                                    <span class="acquisition-persona-card__icon"><i class="ti ti-ball-basketball" aria-hidden="true"></i></span>
                                    <span class="acquisition-persona-card__body">
                                        <strong>Игрок</strong>
                                        <span>Ищу игры и тренировки, участвую в турнирах, присоединяюсь к командам и нахожу, с кем поиграть.</span>
                                    </span>
                                    <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                                </label>

                                <label class="acquisition-persona-card">
                                    <input type="radio" name="onboarding_persona" value="coach" @checked($initialPersona === 'coach')>
                                    <span class="acquisition-persona-card__icon"><i class="ti ti-whistle" aria-hidden="true"></i></span>
                                    <span class="acquisition-persona-card__body">
                                        <strong>Тренер</strong>
                                        <span>Провожу тренировки, создаю секции, набираю игроков и работаю с командой.</span>
                                    </span>
                                    <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                                </label>

                                <label class="acquisition-persona-card">
                                    <input type="radio" name="onboarding_persona" value="venue" @checked($initialPersona === 'venue')>
                                    <span class="acquisition-persona-card__icon"><i class="ti ti-building-stadium" aria-hidden="true"></i></span>
                                    <span class="acquisition-persona-card__body">
                                        <strong>Представитель площадки</strong>
                                        <span>Добавляю площадку, управляю расписанием и бронированиями, нахожу арендаторов.</span>
                                    </span>
                                    <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                                </label>

                                <label class="acquisition-persona-card">
                                    <input type="radio" name="onboarding_persona" value="organizer" @checked($initialPersona === 'organizer')>
                                    <span class="acquisition-persona-card__icon"><i class="ti ti-calendar-event" aria-hidden="true"></i></span>
                                    <span class="acquisition-persona-card__body">
                                        <strong>Организатор</strong>
                                        <span>Создаю игры, тренировки и турниры, собираю участников и управляю мероприятиями.</span>
                                    </span>
                                    <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                                </label>

                                <label class="acquisition-persona-card acquisition-persona-card--quiet">
                                    <input type="radio" name="onboarding_persona" value="explore" @checked($initialPersona === 'explore')>
                                    <span class="acquisition-persona-card__icon"><i class="ti ti-compass" aria-hidden="true"></i></span>
                                    <span class="acquisition-persona-card__body">
                                        <strong>Пока просто посмотрю</strong>
                                        <span>Хочу сначала познакомиться с порталом и выбрать роли позже.</span>
                                    </span>
                                    <i class="ti ti-arrow-right acquisition-persona-card__arrow" aria-hidden="true"></i>
                                </label>
                            </div>
                        </section>

                        <section class="acquisition-onboarding__step" data-acquisition-step="account" hidden>
                            <div class="acquisition-onboarding__step-heading">
                                <span class="acquisition-onboarding__step-label">Твой аккаунт</span>
                                <h2>Создай аккаунт</h2>
                                <p>Осталось совсем немного. Эти данные понадобятся для входа на портал.</p>
                            </div>

                            <div class="acquisition-onboarding__form-grid">
                                <div class="field acquisition-onboarding__field acquisition-onboarding__field--wide">
                                    <label for="acquisitionUsername" class="form-label">Логин</label>
                                    <input id="acquisitionUsername" type="text" name="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror" autocomplete="username" minlength="3" required>
                                    <small>Используется для входа. Публичный никнейм можно настроить отдельно в аккаунте.</small>
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionPassword" class="form-label">Пароль</label>
                                    <input id="acquisitionPassword" type="password" name="password" class="form-control" autocomplete="new-password" required>
                                </div>

                                <div class="field acquisition-onboarding__field">
                                    <label for="acquisitionPasswordConfirmation" class="form-label">Повтори пароль</label>
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
                                <span class="acquisition-onboarding__step-label">Почти готово</span>
                                <h2>Пара деталей о тебе</h2>
                                <p>Для игрока и тренера дата рождения и пол нужны для работы ролевых функций. Имя и фамилию можно заполнить сейчас или позже.</p>
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
                                        <option value="">Выбери</option>
                                        @foreach(UserGenderEnum::cases() as $gender)
                                            <option value="{{ $gender->value }}" @selected(old('gender') === $gender->value)>{{ $gender->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </section>
                    </form>

                    <footer class="acquisition-onboarding__wizard-footer" data-acquisition-footer hidden>
                        <button type="button" class="btn btn--secondary-bordered btn--sm" data-acquisition-back hidden>
                            <i class="ti ti-arrow-left" aria-hidden="true"></i>
                            Назад
                        </button>
                        <span class="acquisition-onboarding__wizard-spacer"></span>
                        <button type="button" class="btn btn--primary btn--sm" data-acquisition-next hidden>
                            Продолжить
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button type="submit" form="acquisitionRegistrationForm" class="btn btn--primary btn--sm" data-acquisition-submit hidden>
                            Создать аккаунт
                            <i class="ti ti-check" aria-hidden="true"></i>
                        </button>
                    </footer>
                @endunless
            </div>

            @unless($isAuthenticatedOnboarding)
                <section id="join-faq" class="acquisition-onboarding__roles-form" aria-labelledby="join-faq-heading" style="scroll-margin-top: 96px;">
                    <div class="acquisition-onboarding__step-heading mb-3">
                        <span class="acquisition-onboarding__step-label">FAQ</span>
                        <h2 id="join-faq-heading">Частые вопросы</h2>
                    </div>

                    <details class="acquisition-onboarding__roles">
                        <summary class="acquisition-onboarding__roles-summary">
                            <span>
                                <small>Регистрация</small>
                                <strong>Регистрация на MSKBA бесплатна?</strong>
                            </span>
                            <i class="ti ti-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="acquisition-onboarding__roles-body">
                            <p>Да. Создать аккаунт и выбрать свои роли на портале можно бесплатно.</p>
                        </div>
                    </details>

                    <details class="acquisition-onboarding__roles mt-2">
                        <summary class="acquisition-onboarding__roles-summary">
                            <span>
                                <small>Роли</small>
                                <strong>Можно изменить или добавить роль позже?</strong>
                            </span>
                            <i class="ti ti-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="acquisition-onboarding__roles-body">
                            <p>Да. Роли не фиксируются навсегда: их можно менять, отключать и добавлять в аккаунте в любое время.</p>
                        </div>
                    </details>

                    <details class="acquisition-onboarding__roles mt-2">
                        <summary class="acquisition-onboarding__roles-summary">
                            <span>
                                <small>QR и геолокация</small>
                                <strong>Нужно обязательно подтверждать геопозицию?</strong>
                            </span>
                            <i class="ti ti-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="acquisition-onboarding__roles-body">
                            <p>Нет. Для QR-кампаний проверка может запускаться при нажатии основной кнопки, но она добровольна: отказ в доступе к геолокации или ошибка определения положения не блокируют регистрацию.</p>
                        </div>
                    </details>
                </section>
            @endunless
        </div>
    </section>

    <script src="{{ asset('js/acquisition-onboarding.js') }}" defer></script>
@endsection