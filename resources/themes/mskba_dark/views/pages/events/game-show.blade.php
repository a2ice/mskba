@php
    use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
    use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
    use App\Modules\Event\Domain\Enums\EventTypeEnum;
    use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
    use App\Modules\Event\Domain\Enums\GameStatusEnum;
    use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
    use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
    use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
    use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;

    abort_unless($game, 404);
    $title = $game->title ?: $event->title;
    $sides = $game->sides->keyBy('slot');
    $roster = $game->rosterEntries->groupBy('game_side_id');
    $stats = $game->playerStatistics->keyBy('user_id');
    $sideA = $sides->get('A');
    $sideB = $sides->get('B');
    $teamLogoUrl = static fn ($side): string => $side?->logoUrl() ?? asset('images/team-placeholder.webp');
    $allows = fn (EventResponsibilityPermissionEnum $permission): bool => $effectivePermissions->contains($permission->value);
    $canUpdate = $allows(EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
    $canManageRoster = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
    $canManageScore = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
    $canManageStatistics = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
    $canComplete = $allows(EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
    $statisticsConfirmed = $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED;
    $isCancelled = $game->status === GameStatusEnum::CANCELLED;
    $isCompleted = $game->status === GameStatusEnum::COMPLETED || $statisticsConfirmed;
    $gameState = match (true) {
        $isCancelled => ['Отменена', 'is-expired'],
        $isCompleted => ['Завершена', 'is-complete'],
        $game->actual_ended_at !== null => ['Ожидает подтверждения', 'is-planned'],
        $game->actual_started_at !== null => ['Идёт', 'is-live'],
        default => ['Ожидает запуска', 'is-planned'],
    };
    $canEnterLiveStatistics = $canManageStatistics && ! $statisticsConfirmed && ! $isCancelled;
    $name = static function ($user): string {
        $profile = $user->profile;
        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name]))) ?: $user->username ?: 'Пользователь #'.$user->id;
    };
    $parentCandidates = $event->participants
        ->where('status', EventParticipantStatusEnum::CONFIRMED)
        ->map(fn ($participant) => $participant->user)->keyBy('id') ?? collect();
    $isEmbeddedGame = $event->type !== EventTypeEnum::GAME;
    $effectiveStartsAt = $isEmbeddedGame ? $game->scheduled_starts_at : $event->starts_at;
    $effectiveEndsAt = $isEmbeddedGame ? $game->scheduled_ends_at : $event->ends_at;
    $gameRouteParameters = [$event->routeIdentifier(), $game->id];
    $managementMode = $managementMode ?? false;
    $tournament = $game->tournamentMatch?->tournament;
    $tournamentCandidates = $tournamentCandidates ?? collect();
    $canEditComposition = $managementMode
        && $canManageRoster
        && $game->actual_started_at === null
        && ! $statisticsConfirmed
        && ! $isCancelled;
    $candidatesForSlot = static function (string $slot, $side) use ($tournament, $tournamentCandidates, $isEmbeddedGame, $parentCandidates) {
        if ($tournament) {
            return $tournamentCandidates->get($slot, collect());
        }
        if ($isEmbeddedGame) {
            return $parentCandidates;
        }

        return ($side?->team?->memberships ?? collect())
            ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE)
            ->map(fn ($membership) => $membership->user)
            ->keyBy('id');
    };
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section
        class="game-control first-screen"
        data-game-control
        @if($managementMode) data-game-lifecycle-url="{{ route('events.games.lifecycle.show', $gameRouteParameters) }}" @endif
        data-game-live-url="{{ route('events.games.live', $gameRouteParameters) }}"
    >
        <div class="inner game-control__inner">
            @if(session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
            @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

            <a class="game-control__back" href="{{ $tournament ? route('tournaments.show', $tournament->routeIdentifier()) : route('events.show', $event->routeIdentifier()) }}"><i class="ti ti-arrow-left"></i>{{ $tournament ? 'Назад к турниру' : 'Назад к мероприятию' }}</a>

            <header class="game-control__header">
                <div class="game-control__identity"><span class="eyebrow">{{ $tournament ? 'Игра турнира' : ($isEmbeddedGame ? 'Мини-игра' : 'Игра') }}</span><h1>{{ $title }}</h1>@if($tournament)<p>Турнир «<a href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">{{ $tournament->title }}</a>»</p>@elseif($isEmbeddedGame)<p>В рамках «<a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a>»</p>@endif</div>
                <div class="game-control__chips"><span>{{ $game->format?->label() ?? $game->formatLabel() }}</span><span>{{ $game->scoring_type->label() }} · {{ $game->timing_mode->label() }}@if($game->periods_count) · {{ $game->periods_count }}@endif</span><span class="{{ $gameState[1] }}"><i class="ti ti-point-filled"></i>{{ $gameState[0] }}</span><span><i class="ti ti-users"></i>Команды из участников</span></div>
                <div class="game-control__meta"><span><i class="ti ti-map-pin"></i>{{ $event->venue->name }}</span><span><i class="ti ti-clock"></i>{{ $effectiveStartsAt && $effectiveEndsAt ? $effectiveStartsAt->format('H:i').'–'.$effectiveEndsAt->format('H:i') : 'Время на игру не задано' }}</span></div>
            </header>

            @if($game->status_comment)
                <div class="alert alert-info"><strong>Комментарий к состоянию игры:</strong> {{ $game->status_comment }}</div>
            @endif

            @if($canManage && !$managementMode)
                <section class="event-card event-game-management-link">
                    <div>
                        <span class="eyebrow">Проведение игры</span>
                        <h2>Счёт и игровые показатели</h2>
                        <p>Откройте панель организатора, запустите игру и фиксируйте броски, передачи, подборы и другие показатели.</p>
                    </div>
                    <a class="btn btn--primary btn--sm" href="{{ route('events.games.manage', $gameRouteParameters) }}">Перейти к управлению игрой</a>
                </section>
            @endif

            <section class="game-scoreboard" data-game-scoreboard data-score-url="{{ route('events.games.score', $gameRouteParameters) }}" data-image-upload-surface>
                @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем счёт…'])
                <div class="game-scoreboard__team is-a"><img class="game-scoreboard__team-logo" src="{{ $teamLogoUrl($sideA) }}" alt="Логотип команды {{ $sideA?->display_name ?: 'A' }}"><strong>{{ $sideA?->display_name ?: 'Команда A' }}</strong></div>
                <div class="game-scoreboard__score"><b data-game-visible-score="A">{{ $sideA?->score ?? 0 }}</b><span>:</span><b data-game-visible-score="B">{{ $sideB?->score ?? 0 }}</b><small>{{ $statisticsConfirmed ? 'Итоговый счёт' : 'Текущий счёт' }}</small></div>
                <div class="game-scoreboard__team is-b"><img class="game-scoreboard__team-logo" src="{{ $teamLogoUrl($sideB) }}" alt="Логотип команды {{ $sideB?->display_name ?: 'B' }}"><strong>{{ $sideB?->display_name ?: 'Команда B' }}</strong></div>
                @if($canManageScore && !$statisticsConfirmed && !$isCancelled)<button class="btn btn--secondary btn--sm game-scoreboard__edit" type="button" data-game-score-open><i class="ti ti-scoreboard"></i>Установить счёт</button>@endif
                @if($isEmbeddedGame && $canUpdate && !$statisticsConfirmed && !$isCancelled)<details class="game-control-editor"><summary class="btn btn--secondary btn--sm"><i class="ti ti-pencil"></i>Редактировать</summary><form method="POST" action="{{ route('events.games.update', $gameRouteParameters) }}" class="row g-3">@csrf @method('PUT')<div class="col-12"><label class="form-label">Название</label><input class="form-control" name="title" value="{{ $title }}" required></div><input type="hidden" name="has_scheduled_time" value="{{ (int) ($game->scheduled_starts_at !== null) }}"><input type="hidden" name="starts_at" value="{{ $game->scheduled_starts_at?->format('H:i') }}"><input type="hidden" name="ends_at" value="{{ $game->scheduled_ends_at?->format('H:i') }}"><input type="hidden" name="side_a_size" value="{{ $game->side_a_size }}"><input type="hidden" name="side_b_size" value="{{ $game->side_b_size }}"><input type="hidden" name="scoring_type" value="{{ $game->scoring_type->value }}"><div class="col-md-6"><label class="form-label">Команда A</label><input class="form-control" name="side_a_name" value="{{ $sideA?->display_name }}"></div><div class="col-md-6"><label class="form-label">Команда B</label><input class="form-control" name="side_b_name" value="{{ $sideB?->display_name }}"></div><div class="col-12"><button class="btn btn--primary btn--sm">Сохранить</button></div></form></details>@endif
            </section>

            <div class="game-lifecycle-primary" data-game-lifecycle-primary>
                <div class="game-lifecycle-actions" data-game-lifecycle-actions></div>
            </div>

            @if($game->timing_mode === GameTimingModeEnum::PERIODS)
                <section class="section-card mb-5"><h2 class="mb-3">Периоды</h2><div class="game-control__chips">@foreach($game->periods as $period)<span @class(['is-live' => $period->status === GamePeriodStatusEnum::IN_PROGRESS, 'is-complete' => $period->status === GamePeriodStatusEnum::COMPLETED])>{{ $period->number }} · {{ $period->status->label() }}@if($period->side_a_score !== null) · {{ $period->side_a_score }}:{{ $period->side_b_score }}@endif</span>@endforeach</div></section>
            @endif

            <form
                method="POST"
                action="{{ route('events.games.statistics', $gameRouteParameters) }}"
                data-game-live-statistics
                data-game-composition-url="{{ route('events.games.roster', $gameRouteParameters) }}"
                data-scoring-type="{{ $game->scoring_type->value }}"
                data-image-upload-surface
            >
                <h2 class="visually-hidden">Статистика игроков</h2>
                @csrf @method('PATCH')
                @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем статистику…'])
                <div class="alert" data-game-live-message role="status" aria-live="polite" hidden></div>
                <input type="hidden" name="scores[A]" value="{{ $sideA?->score ?? 0 }}" data-game-score-input="A"><input type="hidden" name="scores[B]" value="{{ $sideB?->score ?? 0 }}" data-game-score-input="B">
                @foreach(['A', 'B'] as $slot)
                    @php
                        $side = $sides->get($slot);
                        $sideRoster = $roster->get($side?->id, collect());
                        $entriesByUser = $sideRoster->keyBy('user_id');
                        $savedStarters = $sideRoster->where('lineup_role', GameLineupRoleEnum::STARTER);
                        $teamProfile = $side?->team?->sportProfiles
                            ?->first(fn ($profile) => $profile->sport_type->value === $game->scoring_type->value);
                        $defaultStarterMembershipIds = $savedStarters->isEmpty()
                            ? ($teamProfile?->lineupMembers ?? collect())
                                ->where('assignment', TeamLineupAssignmentEnum::STARTER)
                                ->pluck('contract_membership_id')
                            : collect();
                        $players = $canEditComposition
                            ? $candidatesForSlot($slot, $side)->concat($sideRoster->pluck('user'))->unique('id')->keyBy('id')
                            : $sideRoster->pluck('user')->keyBy('id');
                    @endphp
                    <section class="game-team-panel" data-game-side="{{ $slot }}">
                        <header><span><img class="game-team-panel__logo" src="{{ $teamLogoUrl($side) }}" alt="Логотип команды {{ $side?->display_name ?: $slot }}"><strong>{{ $side?->display_name ?: 'Команда '.$slot }}</strong><small>· <span data-game-side-player-count>{{ $sideRoster->count() }}</span> игрока</small></span></header>
                        <div class="game-team-panel__players">
                            @forelse($players as $player)
                                @php
                                    $entry = $entriesByUser->get($player->id);
                                    $stat = $stats->get($player->id);
                                    $isPlaying = $entry !== null;
                                    $isStarter = $entry?->lineup_role === GameLineupRoleEnum::STARTER
                                        || ($canEditComposition
                                            && $entry !== null
                                            && $defaultStarterMembershipIds->contains($entry->source_contract_membership_id));
                                @endphp
                                <article @class(['game-live-player', 'is-not-playing' => $canEditComposition && ! $isPlaying]) data-game-player="{{ $player->id }}" data-game-player-side="{{ $slot }}">
                                    <div class="game-live-player__identity">@if($player->profile?->activeAvatar)<img src="{{ $player->profile->activeAvatar->publicUrl() }}" alt="">@else<span>{{ mb_strtoupper(mb_substr($name($player), 0, 2)) }}</span>@endif<strong>{{ $name($player) }}</strong></div>
                                    <div class="game-live-player__actions">
                                        @if($canEditComposition)
                                            <label class="form-toggle game-player-toggle">
                                                <input class="form-toggle__input" type="checkbox" value="{{ $player->id }}" data-game-playing @checked($isPlaying)>
                                                <span class="form-toggle__control" aria-hidden="true"></span><strong class="form-toggle__title">Играет</strong>
                                            </label>
                                            <label class="form-toggle game-player-toggle">
                                                <input class="form-toggle__input" type="checkbox" value="{{ $player->id }}" data-game-starter @checked($isStarter) @disabled(! $isPlaying)>
                                                <span class="form-toggle__control" aria-hidden="true"></span><strong class="form-toggle__title">В старте</strong>
                                            </label>
                                            <label class="form-toggle game-player-toggle">
                                                <input class="form-toggle__input" type="radio" name="game-captain-{{ $slot }}" value="{{ $player->id }}" data-game-captain @checked((bool) $entry?->is_captain) @disabled(! $isPlaying)>
                                                <span class="form-toggle__control" aria-hidden="true"></span><strong class="form-toggle__title">Капитан</strong>
                                            </label>
                                        @elseif($canEnterLiveStatistics)
                                            <button type="button" data-game-shot-open><i class="ti ti-ball-basketball"></i>Бросок</button>
                                            <button type="button" data-game-stat-increment="assists"><i class="ti ti-hand-click"></i>Передача</button>
                                            <button type="button" data-game-stat-increment="defensive_rebounds"><i class="ti ti-jump-rope"></i>Подбор</button>
                                            <button type="button" data-game-stat-increment="steals"><i class="ti ti-shield"></i>Перехват</button>
                                            <button type="button" data-game-stat-increment="fouls"><i class="ti ti-hand-stop"></i>Фол</button>
                                            <button type="button" class="is-more" data-game-inline-open aria-label="Изменить все показатели"><i class="ti ti-dots"></i></button>
                                        @endif
                                    </div>
                                    <strong class="game-live-player__points"><span data-game-player-points>{{ $stat?->points($game->scoring_type) ?? 0 }}</span> очк.</strong>
                                    @if($entry) @foreach($statisticsFields as $field => $definition)<input type="hidden" name="players[{{ $player->id }}][{{ $field }}]" value="{{ $stat?->{$field} ?? 0 }}" data-game-stat-field="{{ $field }}" data-stat-label="{{ $definition['label'] }}">@endforeach @endif
                                </article>
                            @empty <p>Состав пока не указан.</p> @endforelse
                        </div>
                        @if($canEditComposition)
                            <footer class="game-team-panel__footer">
                                <p class="form-hint">Формат требует {{ $slot === 'A' ? $game->side_a_size : $game->side_b_size }} игроков в старте и одного капитана.</p>
                                <button class="btn btn--primary btn--sm" type="button" data-game-composition-save>Сохранить состав</button>
                            </footer>
                        @endif
                    </section>
                @endforeach
            </form>

            @if(!$isCompleted && !$isCancelled && ($canComplete || $canEnterLiveStatistics))
                <div class="game-lifecycle-actions">
                    @if($canComplete)<button class="btn btn--danger" type="button" data-game-cancel data-cancel-url="{{ route('events.games.cancel', $gameRouteParameters) }}"><i class="ti ti-circle-x"></i>Отменить игру</button>@endif
                    @if($canComplete && $canEnterLiveStatistics)<button class="game-complete-button" type="button" data-game-review-open><i class="ti ti-circle-check"></i>Завершить игру</button>@endif
                </div>
            @endif
        </div>
    </section>

    @component('theme::partials.modal.layout', ['id' => 'game-shot-modal'])
        <form data-game-shot-form><h2 class="modal_title" id="modal-title-game-shot-modal">Добавить бросок</h2><fieldset class="game-shot-range"><legend class="form-label">Тип броска</legend><label><input type="radio" name="range" value="close" checked><span>Ближний</span></label><label><input type="radio" name="range" value="mid"><span>Средний</span></label><label><input type="radio" name="range" value="three"><span>Дальний</span></label><label><input type="radio" name="range" value="free_throw"><span>Штрафной</span></label></fieldset>@include('theme::partials.forms.toggle', ['id' => 'game-shot-made', 'name' => 'made', 'title' => 'Попадание', 'description' => 'По умолчанию бросок считается промахом.', 'checked' => false])<button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-score-modal'])
        <form data-game-score-form><h2 class="modal_title" id="modal-title-game-score-modal">Установить счёт</h2><div class="row g-3"><label class="field col-6"><span class="form-label">{{ $sideA?->display_name }}</span><input class="form-control" type="number" min="0" max="999" name="scores[A]" value="{{ $sideA?->score ?? 0 }}"></label><label class="field col-6"><span class="form-label">{{ $sideB?->display_name }}</span><input class="form-control" type="number" min="0" max="999" name="scores[B]" value="{{ $sideB?->score ?? 0 }}"></label></div><button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-inline-statistics-modal'])
        <form data-game-inline-form><h2 class="modal_title" id="modal-title-game-inline-statistics-modal">Показатели игрока</h2><div class="game-inline-statistics-fields" data-game-inline-fields></div><button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-final-review-modal', 'dialogClass' => 'game-final-review-modal__dialog'])
        <div data-game-final-review data-complete-url="{{ route('events.games.statistics.complete', $gameRouteParameters) }}" data-image-upload-surface>@include('theme::partials.image-upload-loading', ['text' => 'Завершаем игру…'])<h2 class="modal_title" id="modal-title-game-final-review-modal">Итоги игры</h2><p>Проверьте счёт и показатели. После подтверждения они будут зафиксированы.</p><div class="game-final-review__score"><strong data-review-score="A">0</strong><span>:</span><strong data-review-score="B">0</strong></div><div data-game-review-table></div><div class="game-final-review__actions"><button class="btn btn--secondary" type="button" data-game-recalculate><i class="ti ti-calculator"></i>Пересчитать</button><button class="btn btn--primary" type="button" data-game-complete-confirm><i class="ti ti-circle-check"></i>Подтвердить и завершить</button></div></div>
    @endcomponent
@endsection
