@php
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum as PlanPermission;
    use App\Modules\Event\Domain\Enums\GameStatusEnum;
    use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
    use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
    $canPlan = $editable && !$event->primaryGame?->tournamentMatch;
    $canAddGame = $canPlan && $effectivePermissions->contains(PlanPermission::CREATE_MINI_GAME->value);
    $canEditGame = $canPlan && $effectivePermissions->contains(PlanPermission::UPDATE_MINI_GAME->value);
    $canInviteGame = $canPlan && $event->status === EventStatusEnum::PUBLISHED && $effectivePermissions->contains(PlanPermission::MANAGE_PARTICIPANTS->value);
    $canDeleteGame = $canPlan && $effectivePermissions->contains(PlanPermission::DELETE_MINI_GAME->value);
    $teamData = static fn ($team) => $team ? ['id' => $team->id, 'name' => $team->name, 'logo_url' => $team->logo?->publicUrl() ?: asset('images/team-placeholder.webp')] : null;
@endphp
<div class="event-game-plan" data-event-planned-games
    data-create-url="{{ route('events.planned-games.store', $event->routeIdentifier()) }}"
    data-search-url="{{ route('events.funnel.teams') }}">
    <strong>Игры</strong>
    @forelse($event->games as $child)
        @if($child->uses_event_planning)
            @php
                $scheduled = $child->status === GameStatusEnum::SCHEDULED && !$child->actual_started_at && $child->statistics_status === GameStatisticsStatusEnum::NOT_STARTED;
                $config = [
                    'title' => $child->title ?: 'Игра',
                    'format' => $child->format?->value,
                    'recruitment_mode' => $child->recruitment_mode?->value,
                    'scoring_type' => $child->scoring_type?->value,
                    'timing_mode' => $child->timing_mode?->value,
                    'periods_count' => $child->periods_count,
                    'team_a_id' => $child->planned_team_a_id,
                    'team_b_id' => $child->planned_team_b_id,
                ];
                $selectedTeams = ['a' => $teamData($child->plannedTeamA), 'b' => $teamData($child->plannedTeamB)];
                $teamLabel = collect($selectedTeams)->filter()->pluck('name')->implode(' — ');
                $history = $child->admissions_exists || $child->sides->isNotEmpty() || $child->rosterEntries->isNotEmpty()
                    || $child->actions_exists || $child->playerStatistics->isNotEmpty() || $child->sides_confirmed_at;
            @endphp
            <div class="event-game-plan__row" id="planned-game-{{ $child->id }}" data-planned-game-row
                data-game-config='@json($config, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)'
                data-game-teams='@json($selectedTeams, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)'
                data-update-url="{{ route('events.planned-games.update', [$event->routeIdentifier(), $child->id]) }}"
                data-delete-url="{{ route('events.planned-games.destroy', [$event->routeIdentifier(), $child->id]) }}">
                <div class="event-game-plan__details">
                    @if($scheduled && $canEditGame && !$history)
                        <button class="event-game-plan__name" type="button" data-planned-game-edit>{{ $config['title'] }}</button>
                    @else
                        <strong>{{ $config['title'] }}</strong>
                    @endif
                    <p class="event-game-editor__hint">{{ $child->formatLabel() }} · {{ $child->status?->label() }}</p>
                    @if($child->recruitment_mode && $child->side_a_size > 1)
                        <p class="event-game-editor__hint">{{ $child->recruitment_mode->value === 'preformed_teams' ? 'Между командами' : 'Из участников' }}</p>
                    @endif
                    @if($child->recruitment_mode?->value === 'preformed_teams')
                        <div class="event-game-plan__teams">
                            @foreach([$child->plannedTeamA, $child->plannedTeamB] as $team)
                                @continue(!$team)
                                @php
                                    $latest = $child->admissions->where('team_id', $team->id)->sortByDesc('id')->first();
                                    $status = $latest?->status;
                                    $pendingApplication = $status === GameAdmissionStatusEnum::PENDING && $latest->direction === GameAdmissionDirectionEnum::APPLICATION;
                                    $canSend = $scheduled && $canInviteGame && !$child->sides_confirmed_at;
                                    $label = match ($status) {
                                        GameAdmissionStatusEnum::PENDING => $pendingApplication ? 'Заявка на участие' : 'Приглашение отправлено',
                                        GameAdmissionStatusEnum::ACCEPTED => 'Участие подтверждено',
                                        GameAdmissionStatusEnum::DECLINED => 'Отклонено',
                                        GameAdmissionStatusEnum::REVOKED => 'Отозвано',
                                        default => 'Приглашение не отправлено',
                                    };
                                @endphp
                                <div class="event-game-plan__team">
                                    <img src="{{ $teamData($team)['logo_url'] }}" alt="" width="32" height="32">
                                    <div class="event-game-plan__details">
                                        <strong>{{ $team->name }}</strong>
                                        <span class="event-game-editor__hint">{{ $label }}</span>
                                        @if($canSend)
                                            @if($pendingApplication)
                                                <button type="button" class="event-game-plan__add" data-planned-invite-action data-method="POST" data-url="{{ route('events.games.recruitment.respond', [$event->routeIdentifier(), $child->id, $latest->id]) }}" data-payload='{"decision":"accepted"}'>Принять заявку</button>
                                                <button type="button" class="event-game-plan__add" data-planned-invite-action data-method="POST" data-url="{{ route('events.games.recruitment.respond', [$event->routeIdentifier(), $child->id, $latest->id]) }}" data-payload='{"decision":"declined"}'>Отклонить заявку</button>
                                            @elseif($status === GameAdmissionStatusEnum::PENDING)
                                                <button type="button" class="event-game-plan__add" data-planned-invite-action data-method="DELETE" data-url="{{ route('events.games.recruitment.revoke', [$event->routeIdentifier(), $child->id, $latest->id]) }}">Отозвать приглашение</button>
                                            @elseif($status !== GameAdmissionStatusEnum::ACCEPTED)
                                                @if($team->acceptsCompetitionInvitations())
                                                    <button type="button" class="event-game-plan__add" data-planned-invite-action data-method="POST" data-url="{{ route('events.games.recruitment.invite', [$event->routeIdentifier(), $child->id]) }}" data-payload='@json(["team_id" => $team->id])'>{{ $latest ? 'Отправить приглашение повторно' : 'Отправить приглашение' }}</button>
                                                @else
                                                    <span class="event-game-editor__hint">Команда не принимает приглашения</span>
                                                @endif
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if($canInviteGame)<p class="event-game-editor__error" data-planned-invite-error role="alert" hidden></p>@endif
                    @elseif($teamLabel)<p class="event-game-editor__hint">{{ $teamLabel }}</p>@endif
                    {{-- The new sport runtime is connected in the next integration slice. --}}
                </div>
                @if($scheduled && $canEditGame && !$history)
                    <button class="icon-button event-game-plan__icon" type="button" aria-label="Редактировать игру" title="Редактировать игру" data-planned-game-edit>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4-1 12-12-3-3L5 16l-1 4zM14 7l3 3"/></svg>
                    </button>
                @endif
                @if($scheduled && $canDeleteGame && !$history)
                    <button class="icon-button event-game-plan__icon event-game-plan__remove" type="button" aria-label="Удалить игру" title="Удалить игру" data-planned-game-delete>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                    </button>
                @endif
            </div>
        @else
            @if($event->starts_at && $child->hasSportConfiguration())
                <a class="app-venue-activity" href="{{ $event->primary_game_id === $child->id && $event->type->value === 'game' ? route('events.show', ['event' => $event->routeIdentifier(), 'sport' => 1]) : route('events.games.show', [$event->routeIdentifier(), $child->id]) }}">
                    <strong class="app-venue-activity__title">{{ $child->title ?: 'Игра' }}</strong><span class="app-venue-activity__description">{{ $child->formatLabel() }} · {{ $child->status?->label() }}</span>
                </a>
            @else <p>{{ $child->title ?: 'Игра' }} · {{ $child->formatLabel() }}</p> @endif
            @if($event->starts_at && $child->hasSportConfiguration() && $effectivePermissions->contains(PlanPermission::UPDATE_MINI_GAME->value))
                <a class="button secondary" href="{{ route('events.games.manage', [$event->routeIdentifier(), $child->id]) }}">Управлять игрой</a>
            @endif
        @endif
    @empty
        <p>Игр пока нет</p>
    @endforelse
    @if($canAddGame)<button class="event-game-plan__add" type="button" data-planned-game-add>+ Добавить игру</button>@endif
</div>
@include('components.event-game-plan-styles')
@if($canAddGame || $canEditGame || $canDeleteGame)
    @include('components.event-game-editor', ['editorId' => 'event-page-game-editor', 'editorApp' => true])
    @include('components.event-game-delete-dialog', ['planId' => 'event-page-game-plan', 'planApp' => true])
@endif
