@php
    use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
    use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
    use App\Modules\Event\Domain\Enums\GameStatusEnum;
    use App\Modules\Event\Domain\Enums\GameTimingModeEnum;

    $title = $game->title ?: $event->title;
    $sides = $game->sides->keyBy('slot');
    $sideA = $sides->get('A');
    $sideB = $sides->get('B');
    $roster = $game->rosterEntries->groupBy('game_side_id');
    $stats = $game->playerStatistics->keyBy('user_id');
    $activeSide = $game->latestTeamAction?->gameSide?->slot;
    $activePeriod = $game->periods->first(fn ($period) => $period->status === GamePeriodStatusEnum::IN_PROGRESS);
    $statisticsConfirmed = $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED;
    $isFinished = $game->status === GameStatusEnum::COMPLETED || $statisticsConfirmed;
    $isCancelled = $game->status === GameStatusEnum::CANCELLED;
    $effectiveStartsAt = $game->scheduled_starts_at ?? $event->starts_at;
    $effectiveEndsAt = $game->scheduled_ends_at ?? $event->ends_at;
    $isLiveNow = ! $isFinished && ! $isCancelled
        && $game->actual_started_at !== null && $game->actual_ended_at === null;
    $name = static function ($user): string {
        $profile = $user->profile;

        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $user->username
            ?: 'Пользователь #'.$user->id;
    };
    $sideLogo = static fn ($side): ?string => $side?->logoUrl();
    $sideName = static fn ($side, string $slot): string => $side?->display_name ?: 'Команда '.$slot;
    $shortSideName = static function (string $value): string {
        return mb_strlen($value) > 15 ? mb_substr($value, 0, 15).'…' : $value;
    };
    $visibleRosterPlayers = max(1, $game->format?->sideSize() ?? max($game->side_a_size, $game->side_b_size));
    $rosterViewportHeight = ($visibleRosterPlayers * 132) + (($visibleRosterPlayers - 1) * 10);
    $compactRosterViewportHeight = ($visibleRosterPlayers * 108) + (($visibleRosterPlayers - 1) * 10);
@endphp

@extends('theme::layouts.app', ['title' => 'Live · '.$title])

@section('content')
    <main
        class="game-live-screen"
        data-game-live-screen
        data-game-id="{{ $game->id }}"
        data-game-event-id="{{ $event->id }}"
        data-game-live-active-side="{{ $activeSide }}"
        data-game-live-channel="game.live.{{ $game->id }}"
        data-game-live-snapshot-url="{{ route('events.games.live.snapshot', [$event->routeIdentifier(), $game->id]) }}"
        data-game-live-audience-url="{{ route('events.games.live.audience', [$event->routeIdentifier(), $game->id]) }}"
        data-game-live-audience-interval="{{ max(30, (int) config('game_live.heartbeat_interval_seconds', 45)) }}"
        data-game-live-terminal="{{ (int) ($game->actual_ended_at !== null || $isFinished || $isCancelled) }}"
    >
        <header class="game-live-header">
            <a class="game-live-brand" href="{{ route('welcome') }}" aria-label="MSKBA">
                <img src="{{ asset('images/logo-header-cropped.png') }}" alt="MSKBA">
            </a>
            <div class="game-live-header__status {{ $isLiveNow ? 'is-live' : '' }}">
                <span class="game-live-pulse" aria-hidden="true" @if(!$isLiveNow) hidden @endif></span>
                <span data-game-live-status>{{ $isLiveNow ? 'LIVE' : ($isFinished ? 'ЗАВЕРШЕНА' : ($isCancelled ? 'ОТМЕНЕНА' : 'ТРАНСЛЯЦИЯ')) }}</span>
                <span class="game-live-header__audience" data-game-live-audience title="Авторизованные зрители / все зрители" data-tooltip-variant="title" hidden>
                    <i class="ti ti-eye" aria-hidden="true"></i>
                    <span><strong data-game-live-audience-authenticated>0</strong>/<strong data-game-live-audience-total>0</strong></span>
                </span>
                <span class="game-live-header__period" data-game-live-active-period="{{ $activePeriod?->number }}" @if($activePeriod === null) hidden @endif>
                    @if($activePeriod !== null)ПЕРИОД {{ $activePeriod->number }} ИЗ {{ $game->periods_count }}@endif
                </span>
            </div>
            <a class="game-live-close" href="{{ route('events.games.show', [$event->routeIdentifier(), $game->id]) }}" aria-label="Вернуться к игре">
                <i class="ti ti-x"></i>
            </a>
        </header>

        <section class="game-live-stage" aria-label="Счёт игры">
            <p class="game-live-stage__eyebrow">{{ $title }}</p>
            <div class="game-live-score" aria-label="{{ $sideA?->score ?? 0 }} : {{ $sideB?->score ?? 0 }}">
                <strong data-game-live-score="A" @class(['is-active' => $activeSide === 'A'])>{{ $sideA?->score ?? 0 }}</strong>
                <span>:</span>
                <strong data-game-live-score="B" @class(['is-active' => $activeSide === 'B'])>{{ $sideB?->score ?? 0 }}</strong>
            </div>

            <div class="game-live-teams">
                @foreach(['A' => $sideA, 'B' => $sideB] as $slot => $side)
                    @php $fullSideName = $sideName($side, $slot); @endphp
                    <article class="game-live-team is-{{ strtolower($slot) }}">
                        <div class="game-live-team__logo">
                            @if($sideLogo($side))
                                <img
                                    src="{{ $sideLogo($side) }}"
                                    alt="Логотип {{ $fullSideName }}"
                                    data-game-live-team-logo
                                >
                            @endif
                            <i
                                class="ti ti-shirt-sport"
                                aria-hidden="true"
                                data-game-live-team-logo-fallback
                                @if($sideLogo($side)) hidden @endif
                            ></i>
                        </div>
                        <h1 title="{{ $fullSideName }}" data-tooltip-variant="title" aria-label="{{ $fullSideName }}">{{ $shortSideName($fullSideName) }}</h1>
                    </article>
                @endforeach
            </div>

            <button class="game-live-stats-button" type="button" data-game-live-stats-open>
                <i class="ti ti-chart-bar"></i>
                Статистика
            </button>
        </section>

        <section class="game-live-stats" data-game-live-stats hidden aria-label="Текущая статистика">
            <button class="game-live-stats__backdrop" type="button" data-game-live-stats-close aria-label="Закрыть статистику"></button>
            <div class="game-live-stats__sheet" role="dialog" aria-modal="true" aria-labelledby="game-live-stats-title">
                <header>
                    <div>
                        <span>LIVE</span>
                        <h2 id="game-live-stats-title">Статистика игры</h2>
                    </div>
                    <button type="button" data-game-live-stats-close aria-label="Закрыть"><i class="ti ti-x"></i></button>
                </header>

                <div
                    class="game-live-stats__content"
                    style="--game-live-roster-height: {{ $rosterViewportHeight }}px; --game-live-roster-height-compact: {{ $compactRosterViewportHeight }}px;"
                >
                    @foreach(['A' => $sideA, 'B' => $sideB] as $slot => $side)
                        <section class="game-live-stats-team">
                            @php $fullSideName = $sideName($side, $slot); @endphp
                            <h3 title="{{ $fullSideName }}" data-tooltip-variant="title">{{ $shortSideName($fullSideName) }}</h3>
                            <div class="game-live-stats-team__players" data-game-live-team-players="{{ $slot }}">
                                @forelse($roster->get($side?->id, collect()) as $entry)
                                    @php $stat = $stats->get($entry->user_id); @endphp
                                    <article class="game-live-stat-player" data-game-live-player="{{ $entry->user_id }}">
                                        <div class="game-live-stat-player__identity">
                                            @if($entry->user->profile?->activeAvatar)
                                                <img src="{{ $entry->user->profile->activeAvatar->publicUrl() }}" alt="">
                                            @else
                                                <span>{{ mb_strtoupper(mb_substr($name($entry->user), 0, 2)) }}</span>
                                            @endif
                                            <strong>{{ $name($entry->user) }}</strong>
                                        </div>
                                        <strong class="game-live-stat-player__points" data-game-live-player-points>{{ $stat?->points($game->scoring_type) ?? 0 }} <small>очк.</small></strong>
                                        <dl>
                                            @foreach($statisticsFields as $field => $definition)
                                                @php $value = (int) ($stat?->{$field} ?? 0); @endphp
                                                @if($value > 0)
                                                    <div><dt>{{ $definition['label'] }}</dt><dd data-game-live-stat="{{ $field }}">{{ $value }}</dd></div>
                                                @endif
                                            @endforeach
                                        </dl>
                                    </article>
                                @empty
                                    <p class="game-live-stats-empty">Состав пока не указан.</p>
                                @endforelse
                            </div>
                        </section>
                    @endforeach
                    @if($game->timing_mode === GameTimingModeEnum::PERIODS)
                        <hr><section class="game-live-stats-team game-live-stats-periods" data-game-live-periods><h3>По периодам</h3>@foreach($periodStatistics as $period)<details><summary>Период {{ $period['number'] }} · {{ $period['score_a'] ?? 0 }}:{{ $period['score_b'] ?? 0 }}</summary>@forelse($period['players'] as $userId => $values)<p><strong>{{ $name($game->rosterEntries->firstWhere('user_id', $userId)?->user) }}</strong>: {{ collect($values)->map(fn($value, $field) => ($statisticsFields[$field]['label'] ?? ($field === 'points' ? 'Очки' : $field)).' '.$value)->implode(', ') }}</p>@empty<p>Действий пока нет.</p>@endforelse</details>@endforeach</section>
                    @endif
                </div>
            </div>
        </section>

        <section class="game-live-event" data-game-live-event hidden aria-live="assertive" aria-atomic="true">
            <div class="game-live-event__backdrop"></div>
            <article class="game-live-event__card">
                <div class="game-live-event__logo" data-game-live-event-logo-wrap>
                    <img data-game-live-event-logo alt="" hidden>
                    <i class="ti ti-shirt-sport" data-game-live-event-logo-fallback aria-hidden="true"></i>
                </div>
                <p class="game-live-event__team" data-game-live-event-team></p>
                <strong class="game-live-event__label" data-game-live-event-label></strong>
                <p class="game-live-event__player" data-game-live-event-player></p>
            </article>
        </section>
    </main>
@endsection
