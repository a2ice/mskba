@extends('theme::layouts.section-sidebar', [
    'title' => 'Игра · '.$event->title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => 'Управление игрой',
    'contentSubtitle' => $event->title,
])

@php
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;

    $sides = $event->gameSides->keyBy('slot');
    $roster = $event->gameRosterEntries->groupBy('game_side_id');
    $stats = $event->gamePlayerStatistics->keyBy('user_id');
    $statisticsConfirmed = $event->gameDetail->statistics_status === \App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum::CONFIRMED;
    $name = static function ($user): string {
        $profile = $user->profile;
        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $user->username
            ?: 'Пользователь #'.$user->id;
    };
    $parentCandidates = $event->parentEvent === null
        ? collect()
        : $event->parentEvent->participants
            ->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED)
            ->map(fn ($participant) => $participant->user)
            ->keyBy('id');
    $miniGameStartsAt = $event->gameDetail->is_time_scheduled
        ? old('starts_at', $event->starts_at->format('H:i'))
        : '';
    $miniGameEndsAt = $event->gameDetail->is_time_scheduled
        ? old('ends_at', $event->ends_at->format('H:i'))
        : '';
    $hasMiniGameScheduledTime = (bool) old(
        'has_scheduled_time',
        $event->gameDetail->is_time_scheduled,
    );
    $allows = fn (EventResponsibilityPermissionEnum $permission): bool => $effectivePermissions->contains($permission->value);
    $canUpdateMiniGame = $allows(EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
    $canDeleteMiniGame = $allows(EventResponsibilityPermissionEnum::DELETE_MINI_GAME);
    $canManageRoster = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
    $canManageStatistics = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
    $canManageScore = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
    $canCompleteMiniGame = $allows(EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
@endphp

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Игра</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('events.show', $event->routeIdentifier()) }}">Обзор</a></li>
            <li class="nav-item active"><a class="nav-link active" href="{{ route('events.game.manage', $event->routeIdentifier()) }}">Состав и статистика</a></li>
            @if($event->parentEvent)
                <li class="nav-item"><a class="nav-link" href="{{ route('events.show', $event->parentEvent->routeIdentifier()) }}">К тренировке</a></li>
            @endif
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
    @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

    <section class="section-card">
        <span class="eyebrow">Формат {{ $event->gameDetail->formatLabel() }}</span>
        @if($event->parentEvent && !$statisticsConfirmed && ($canUpdateMiniGame || $canDeleteMiniGame))
            <details class="event-mini-games__create mb-4">
                <summary>Параметры мини-игры</summary>
                @if($canUpdateMiniGame)
                    <form method="POST" action="{{ route('events.game.update', $event->routeIdentifier()) }}">
                    @csrf @method('PUT')
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Название</label>
                            <input class="form-control" name="title" value="{{ old('title', $event->title) }}" required>
                        </div>
                        <div class="col-12">
                            @include('theme::partials.forms.toggle', [
                                'id' => 'mini-game-has-scheduled-time',
                                'name' => 'has_scheduled_time',
                                'title' => 'Указать время',
                                'description' => 'Включите, только если у мини-игры заранее известен точный интервал.',
                                'checked' => $hasMiniGameScheduledTime,
                                'wrapperClass' => 'mb-0',
                                'inputAttributes' => [
                                    'data-mini-game-schedule-toggle' => true,
                                ],
                            ])
                        </div>
                        <div
                            class="col-md-3"
                            data-mini-game-schedule-field
                            @if(! $hasMiniGameScheduledTime) hidden @endif
                        >
                            <label class="form-label">Начало</label>
                            <input
                                class="form-control"
                                type="time"
                                name="starts_at"
                                value="{{ $miniGameStartsAt }}"
                                autocomplete="off"
                                data-mini-game-schedule-input
                                @disabled(! $hasMiniGameScheduledTime)
                            >
                        </div>
                        <div
                            class="col-md-3"
                            data-mini-game-schedule-field
                            @if(! $hasMiniGameScheduledTime) hidden @endif
                        >
                            <label class="form-label">Окончание</label>
                            <input
                                class="form-control"
                                type="time"
                                name="ends_at"
                                value="{{ $miniGameEndsAt }}"
                                autocomplete="off"
                                data-mini-game-schedule-input
                                @disabled(! $hasMiniGameScheduledTime)
                            >
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Игроков A</label>
                            <input class="form-control" type="number" min="1" max="7" name="side_a_size" value="{{ old('side_a_size', $event->gameDetail->side_a_size) }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Игроков B</label>
                            <input class="form-control" type="number" min="1" max="7" name="side_b_size" value="{{ old('side_b_size', $event->gameDetail->side_b_size) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Название команды A</label>
                            <input class="form-control" name="side_a_name" value="{{ old('side_a_name', $sides['A']->display_name) }}" maxlength="80" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Название команды B</label>
                            <input class="form-control" name="side_b_name" value="{{ old('side_b_name', $sides['B']->display_name) }}" maxlength="80" required>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn--primary btn--sm" type="submit">Сохранить параметры</button>
                    </div>
                    </form>
                @endif
                @if($canDeleteMiniGame)
                    <form class="mt-2" method="POST" action="{{ route('events.game.destroy', $event->routeIdentifier()) }}" onsubmit="return confirm('Удалить мини-игру? Это действие нельзя отменить.')">
                        @csrf @method('DELETE')
                        <button class="btn btn--danger btn--sm" type="submit">Удалить мини-игру</button>
                    </form>
                @endif
            </details>
        @endif
        <h2>Состав на игру</h2>
        <p>Это снимок состава именно для этой игры. Изменения здесь не меняют постоянный состав команды.</p>
        @if($statisticsConfirmed)
            <div class="alert alert-info">Статистика подтверждена: состав зафиксирован как исторический снимок.</div>
        @endif
        <form method="POST" action="{{ route('events.game.roster', $event->routeIdentifier()) }}">
            @csrf @method('PATCH')
            <div class="game-roster-grid">
                @foreach(['A', 'B'] as $slot)
                    @php
                        $side = $sides[$slot];
                        $selected = $roster->get($side->id, collect())->pluck('user_id');
                        $candidates = $event->parent_event_id
                            ? $parentCandidates
                            : $side->team->memberships->map(fn ($membership) => $membership->user)->keyBy('id');
                    @endphp
                    <fieldset class="game-side-card">
                        <legend>{{ $side->display_name }}</legend>
                        @forelse($candidates as $user)
                            <label class="form-check">
                                <input type="checkbox" name="side_{{ strtolower($slot) }}_user_ids[]" value="{{ $user->id }}" @checked($selected->contains($user->id)) @disabled(!$canManageRoster || $statisticsConfirmed)>
                                <span>{{ $name($user) }}</span>
                            </label>
                        @empty
                            <p>Доступных игроков пока нет.</p>
                        @endforelse
                    </fieldset>
                @endforeach
            </div>
            @if(!$statisticsConfirmed && $canManageRoster)
                <button class="btn btn--primary btn--sm" type="submit">Сохранить состав</button>
            @endif
        </form>
    </section>

    @if($canManageScore || $canManageStatistics || $canCompleteMiniGame)
    <section class="section-card">
        <span class="eyebrow">{{ $event->gameDetail->statistics_status->label() }}</span>
        <h2>Результат и статистика</h2>
        <div class="alert" data-game-statistics-message role="status" aria-live="polite" hidden></div>
        <form
            method="POST"
            action="{{ $canManageStatistics ? route('events.game.statistics', $event->routeIdentifier()) : route('events.game.score', $event->routeIdentifier()) }}"
            data-game-statistics-form
            data-image-upload-surface
        >
            @csrf @method('PATCH')
            @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем статистику…'])
            <div class="game-score-row">
                @foreach(['A', 'B'] as $slot)
                    @php
                        $sidePlayerPoints = $roster->get($sides[$slot]->id, collect())
                            ->sum(fn ($entry) => $stats->get($entry->user_id)?->points() ?? 0);
                    @endphp
                    <label>
                        <span>{{ $sides[$slot]->display_name }}</span>
                        <input
                            class="form-control"
                            type="number"
                            min="0"
                            max="999"
                            name="scores[{{ $slot }}]"
                            value="{{ old('scores.'.$slot, $sides[$slot]->score) }}"
                            data-game-score="{{ $slot }}"
                            @disabled(!$canManageScore && !$canManageStatistics)
                        >
                        <small class="game-score-row__calculated" data-game-calculated-score="{{ $slot }}">
                            По игрокам: {{ $sidePlayerPoints }}
                        </small>
                    </label>
                @endforeach
            </div>
            @if($canManageStatistics)
            <div class="game-statistics-table-wrap">
                <table class="game-statistics-table">
                    <thead>
                    <tr>
                        <th>Игрок</th>
                        @foreach($statisticsFields as $definition)
                            <th><span title="{{ $definition['tooltip'] }}" data-tooltip-variant="title" tabindex="0">{{ $definition['label'] }}</span></th>
                        @endforeach
                        <th><span title="Очки, рассчитанные по попаданиям игрока." data-tooltip-variant="title" tabindex="0">Очки*</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($event->gameRosterEntries as $entry)
                        @php $stat = $stats->get($entry->user_id); @endphp
                        <tr
                            data-game-statistics-row
                            data-player-id="{{ $entry->user_id }}"
                            data-side="{{ $sides->firstWhere('id', $entry->game_side_id)?->slot }}"
                        >
                            <th>{{ $name($entry->user) }}</th>
                            @foreach($statisticsFields as $field => $definition)
                                <td>
                                    <input
                                        type="number"
                                        min="0"
                                        max="999"
                                        name="players[{{ $entry->user_id }}][{{ $field }}]"
                                        value="{{ old('players.'.$entry->user_id.'.'.$field, $stat?->{$field} ?? 0) }}"
                                        aria-label="{{ $definition['label'] }} · {{ $name($entry->user) }}"
                                        data-game-statistic-field="{{ $field }}"
                                    >
                                </td>
                            @endforeach
                            <td data-game-player-points>{{ $stat?->points() ?? 0 }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-hint">* Очки игроков считаются по попаданиям. Счёт команды хранится отдельно: расхождение допустимо, но требует проверки.</p>
            @endif
            @unless($statisticsConfirmed)
                <div class="game-statistics-actions">
                    @if($canManageStatistics)<button class="btn btn--secondary btn--sm" type="button" data-game-statistics-calculate>Подсчитать</button>@endif
                    @if($canManageScore || $canManageStatistics)<button class="btn btn--primary btn--sm" type="submit" data-game-statistics-submit>Сохранить</button>@endif
                    @if($canManageStatistics && $canCompleteMiniGame)
                    <button
                        class="btn btn--secondary btn--sm"
                        type="submit"
                        data-game-statistics-complete
                        data-complete-url="{{ route('events.game.statistics.complete', $event->routeIdentifier()) }}"
                        data-side-a-name="{{ $sides['A']->display_name }}"
                        data-side-b-name="{{ $sides['B']->display_name }}"
                    >Сохранить и завершить игру</button>
                    @endif
                </div>
            @endunless
        </form>
    </section>
    @endif
@endsection
