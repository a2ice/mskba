@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
@endphp

@php $title = $title ?? 'Роль в проекте'; @endphp
@php
    $breadcrumbs = isset($role) ? [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Роли в проекте', 'url' => route('account.roles')],
        ['label' => $role->label()],
    ] : null;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(isset($participationRole))
        <div class="mb-4">
            <h3 class="h3 mb-3">{{ $role->label() }}</h3>

            <ul class="list-unstyled mb-0">
                <li class="list-unstyled mb-2">
                    Статус:
                    <span class="fw-bold">{{ $participationRole->status->label() }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Назначена:
                    <span class="fw-bold">{{ $participationRole->assigned_at?->format('d.m.Y H:i') ?? 'не указано' }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Источник:
                    <span class="fw-bold">{{ $participationRole->assigner?->label() ?? 'не указан' }}</span>
                </li>
                @if($participationRole->expires_at)
                    <li class="list-unstyled mb-2">
                        Действует до:
                        <span class="fw-bold">{{ $participationRole->expires_at->format('d.m.Y H:i') }}</span>
                    </li>
                @endif
                @if($participationRole->comment)
                    <li class="list-unstyled mb-2">
                        Комментарий:
                        <span class="fw-bold">{{ $participationRole->comment }}</span>
                    </li>
                @endif
            </ul>
        </div>

        @if($role === UserParticipationRoleEnum::PLAYER)
            @php
                $profile = $user->playerProfile;
                $objectiveAssessment = $user->playerObjectiveAssessment;
                $availableObjectiveSkills = collect($objectivePlayerSkills)
                    ->filter(fn ($label, $skill) => $objectiveAssessment?->{$skill} !== null);
                $shootingSkillKeys = [
                    'close_range_shooting',
                    'mid_range_shooting',
                    'long_range_shooting',
                ];
                $selectedPositions = old(
                    'positions',
                    $profile?->positions->map(fn ($item) => $item->position->value)->all() ?? [],
                );
            @endphp

            <form
                method="POST"
                action="{{ route('account.player-profile.update') }}"
                class="account-player-profile"
            >
                @csrf
                @method('PATCH')

                @include('theme::pages.account.partials.player-character-section')

                <section class="account-player-profile__section account-player-objective">
                    <div class="account-player-objective__heading">
                        <div>
                            <h3 class="h4 mb-1">Объективные игровые показатели</h3>
                            <p class="text-muted mb-0">Рассчитываются по подтверждённой статистике сыгранных матчей.</p>
                        </div>
                        @if($objectiveAssessment)
                            <div class="account-player-objective__meta">
                                <strong>Матчей учтено: {{ $objectiveAssessment->games_count }}</strong>
                                <span title="Достоверность растёт с количеством подтверждённых матчей и достигает максимума после десяти игр." data-tooltip-variant="title" tabindex="0">
                                    Достоверность {{ (int) round((float) $objectiveAssessment->confidence * 100) }}%
                                </span>
                            </div>
                        @endif
                    </div>

                    @if($objectiveAssessment && $availableObjectiveSkills->isNotEmpty())
                        <div class="account-player-objective__grid">
                            @foreach($availableObjectiveSkills as $skill => $label)
                                @php
                                    $score = (float) $objectiveAssessment->{$skill};
                                    $formattedScore = rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');
                                @endphp
                                <article class="account-player-objective__skill" data-objective-skill="{{ $skill }}">
                                    <div><span>{{ $label }}</span><strong>{{ $formattedScore }}</strong><small>/10</small></div>
                                    <span class="account-player-objective__track" aria-hidden="true">
                                        <span style="width: {{ min(100, max(0, $score * 10)) }}%"></span>
                                    </span>
                                </article>
                            @endforeach
                        </div>
                        <p class="account-player-objective__updated">
                            Обновлено {{ $objectiveAssessment->calculated_at?->format('d.m.Y H:i') }}
                        </p>
                    @else
                        <div class="account-player-objective__empty">
                            <i class="ti ti-chart-dots" aria-hidden="true"></i>
                            <div><strong>Показателей пока нет</strong><span>Они появятся после подтверждения статистики первой сыгранной игры.</span></div>
                        </div>
                    @endif
                </section>

                <section class="account-player-profile__section">
                    <div>
                        <h3 class="h4 mb-1">Самооценка игровых навыков</h3>
                        <p class="text-muted mb-0">
                            Оцените свои текущие навыки от 1 до 10. Самооценка хранится отдельно от показателей матчей.
                        </p>
                    </div>

                    <div class="account-player-profile__skills">
                        @foreach($playerSkills as $skill => $label)
                            @continue(in_array($skill, $shootingSkillKeys, true))
                            <div class="form-group field account-player-profile__field">
                                <label for="player-skill-{{ $skill }}">{{ $label }}</label>
                                @include('theme::partials.forms.score-range', [
                                    'id' => 'player-skill-'.$skill,
                                    'fieldName' => 'self_assessment['.$skill.']',
                                    'oldFieldName' => 'self_assessment.'.$skill,
                                    'value' => $profile?->selfAssessment?->{$skill} ?? 5,
                                ])
                                @error('self_assessment.'.$skill)
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach

                        <fieldset class="account-player-profile__shooting">
                            <legend class="eyebrow">Бросок</legend>
                            <div class="account-player-profile__shooting-grid">
                                @foreach($shootingSkillKeys as $skill)
                                    <div class="form-group field account-player-profile__field">
                                        <label for="player-skill-{{ $skill }}">{{ $playerSkills[$skill] }}</label>
                                        @include('theme::partials.forms.score-range', [
                                            'id' => 'player-skill-'.$skill,
                                            'fieldName' => 'self_assessment['.$skill.']',
                                            'oldFieldName' => 'self_assessment.'.$skill,
                                            'value' => $profile?->selfAssessment?->{$skill} ?? 5,
                                        ])
                                        @error('self_assessment.'.$skill)
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>
                </section>

                <section class="account-player-profile__section">
                    <div class="form-group field account-player-profile__field account-player-profile__field--wide">
                        <label for="player-comment">О себе как об игроке</label>
                        <textarea
                            id="player-comment"
                            class="form-control"
                            name="comment"
                            rows="4"
                            maxlength="1000"
                        >{{ old('comment', $profile?->comment) }}</textarea>
                        @error('comment') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </section>

                <div class="account-player-profile__actions">
                    <button type="submit" name="redirect_to" value="role" class="btn btn--primary btn--xs">
                        Сохранить
                    </button>
                    <button type="submit" name="redirect_to" value="account" class="btn btn--secondary btn--xs">
                        Сохранить и закрыть
                    </button>
                </div>
            </form>
        @else
            <p class="mb-0">Детальная карточка этой роли будет расширена позже.</p>
        @endif
    @endif
@endsection
