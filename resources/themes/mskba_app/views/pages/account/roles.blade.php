@extends('theme::layouts.account', ['title' => 'Роли в проекте'])

@section('account-content')
    <div class="app-roles-page">
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Роли в проекте',
        'subtitle' => 'Выбери, как ты хочешь участвовать в жизни MSKBA. Можно выбрать сразу несколько ролей.',
    ])

    @php
        $roleGroups = [
            'Основные роли' => ['player', 'coach', 'referee', 'venue_related'],
            'Другие направления' => ['media', 'statistician', 'organizer'],
        ];
        $requiresSetup = app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup(auth()->user());
    @endphp

    <section class="app-roles" aria-label="Настройка ролей участия"
             data-role-editor data-role-account-url="{{ route('account.roles') }}">
        <p class="app-roles__feedback" role="status" aria-live="polite" data-role-feedback></p>

        @if ($requiresSetup)
            <div class="notice app-roles__message app-roles__pending" role="status">
                <strong>Сначала заверши регистрацию</strong>
                <p>Ты можешь посмотреть роли и выбрать нужные. Сохранить изменения получится после последнего шага регистрации.</p>
                <a class="button primary" href="{{ route('account.privacy.distribution') }}" data-open-onboarding>Завершить регистрацию</a>
            </div>
        @endif

        @foreach ($roleGroups as $groupTitle => $groupRoles)
            <section class="panel app-roles__group" aria-labelledby="app-roles-group-{{ $loop->index }}">
                <h2 id="app-roles-group-{{ $loop->index }}">{{ $groupTitle }}</h2>
                <div class="app-roles__grid">
                    @foreach ($roles as $role)
                        @continue(! in_array($role->value, $groupRoles, true))
                        @php($isActive = in_array($role->value, $activeRoleValues, true))
                        @php($cooldownSeconds = (int) ($roleCooldownSeconds[$role->value] ?? 0))
                        <article class="app-roles__card"
                                 data-role-card
                                 data-role-id="{{ $role->value }}"
                                 data-role-cooldown-seconds="{{ $cooldownSeconds }}"
                                 data-role-url="{{ route('account.roles.update-one', ['role' => $role->value]) }}">
                            <div class="app-roles__choice">
                                <div class="app-roles__copy">
                                    <div class="app-roles__heading">
                                        <label for="app-participation-role-{{ $role->value }}"
                                               class="app-roles__name"><strong>{{ $role->label() }}</strong></label>
                                        <span class="app-roles__actions" data-role-parameters @if (! $isActive) hidden @endif>
                                            <a href="{{ route('account.participation-role', ['role' => $role->value]) }}"
                                               class="app-roles__settings-link"
                                               title="Параметры роли {{ $role->label() }}"
                                               aria-label="Параметры роли {{ $role->label() }}">
                                                <svg width="14" height="14" viewBox="0 0 24 24"
                                                     fill="none" stroke="currentColor" stroke-width="1.7"
                                                     stroke-linecap="round" stroke-linejoin="round"
                                                     aria-hidden="true" focusable="false">
                                                    <path d="M12 15a3 3 0 1 0 0 -6a3 3 0 0 0 0 6"/>
                                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1 -2.83 2.83l-.06-.06a1.65 1.65 0 0 0 -1.82-.33a1.65 1.65 0 0 0 -1 1.51V21a2 2 0 1 1 -4 0v-.09a1.65 1.65 0 0 0 -1.08-1.5a1.65 1.65 0 0 0 -1.82.33l-.06.06a2 2 0 1 1 -2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82a1.65 1.65 0 0 0 -1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.5-1.08a1.65 1.65 0 0 0 -.33-1.82L4.2 7.22a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1.08 1.5a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0 -.33 1.82V9c.2.62.82 1.03 1.47 1.03H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0 -1.51 1z"/>
                                                </svg>
                                            </a>
                                        </span>
                                    </div>
                                    <label for="app-participation-role-{{ $role->value }}"
                                           class="app-roles__description"><small>{{ $role->description() }}</small></label>
                                </div>
                                <span class="app-roles__control switch-label">
                                    <input id="app-participation-role-{{ $role->value }}"
                                           type="checkbox"
                                           value="1"
                                           @checked($isActive)
                                           @disabled($cooldownSeconds > 0)
                                           aria-describedby="app-role-cooldown-{{ $role->value }}"
                                           aria-label="{{ $role->label() }} — роль участия">
                                    <span id="app-role-cooldown-{{ $role->value }}"
                                          class="app-roles__cooldown"
                                          data-role-cooldown
                                          @if ($cooldownSeconds === 0) hidden @endif>{{ $cooldownSeconds }} с</span>
                                </span>
                            </div>
                            <span class="app-roles__loader" data-role-loader hidden aria-hidden="true">
                                <span class="spinner"></span>
                                <span>Сохраняем…</span>
                            </span>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </section>
    </div>
@endsection
