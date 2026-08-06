@php
    use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
    use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
    use App\Modules\Event\Domain\Enums\EventTypeEnum;
    use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
    use App\Modules\Event\Domain\Enums\GameStatusEnum;

    abort_unless($game, 404);
    $title = $game->title ?: $event->title;
    $sides = $game->sides->keyBy('slot');
    $roster = $game->rosterEntries->groupBy('game_side_id');
    $stats = $game->playerStatistics->keyBy('user_id');
    $sideA = $sides->get('A');
    $sideB = $sides->get('B');
    $allows = fn (EventResponsibilityPermissionEnum $permission): bool => $effectivePermissions->contains($permission->value);
    $canUpdate = $allows(EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
    $canManageRoster = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
    $canManageScore = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
    $canManageStatistics = $allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
    $canComplete = $allows(EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
    $statisticsConfirmed = $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED;
    $isCancelled = $game->status === GameStatusEnum::CANCELLED;
    $isCompleted = $game->status === GameStatusEnum::COMPLETED || $statisticsConfirmed;
    $effectiveStartsAt = $game->scheduled_starts_at ?? $event->starts_at;
    $effectiveEndsAt = $game->scheduled_ends_at ?? $event->ends_at;
    $hasEndedByTime = $effectiveEndsAt->isPast();
    $gameState = match (true) {
        $isCancelled => ['Отменена', 'is-expired'],
        $isCompleted => ['Завершена', 'is-complete'],
        $hasEndedByTime => ['Время истекло', 'is-expired'],
        $effectiveStartsAt->isFuture() => ['Запланирована', 'is-planned'],
        default => ['Идёт', 'is-live'],
    };
    $canEnterLiveStatistics = $canManageStatistics && ! $statisticsConfirmed && ! $isCancelled;
    $name = static function ($user): string {
        $profile = $user->profile;
        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name]))) ?: $user->username ?: 'Пользователь #'.$user->id;
    };
    $parentCandidates = $event->participants
        ->where('status', EventParticipantStatusEnum::CONFIRMED)
        ->map(fn ($participant) => $participant->user)->keyBy('id') ?? collect();
    $legacyIdentifier = $game->legacyEvent?->routeIdentifier();
    $isEmbeddedGame = $event->type !== EventTypeEnum::GAME;
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="game-control first-screen" data-game-control>
        <div class="inner game-control__inner">
            @if(session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
            @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

            <a class="game-control__back" href="{{ route('events.show', $event->routeIdentifier()) }}"><i class="ti ti-arrow-left"></i>Назад к мероприятию</a>

            <header class="game-control__header">
                <div><span class="eyebrow">{{ $isEmbeddedGame ? 'Мини-игра' : 'Игра' }}</span><h1>{{ $title }}</h1>@if($isEmbeddedGame)<p>В рамках «<a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a>»</p>@endif</div>
                <div class="game-control__chips"><span>{{ $game->formatLabel() }}</span><span>{{ $game->scoring_type->label() }}</span><span class="{{ $gameState[1] }}"><i class="ti ti-point-filled"></i>{{ $gameState[0] }}</span><span><i class="ti ti-users"></i>Команды из участников</span></div>
                <div class="game-control__meta"><span><i class="ti ti-map-pin"></i>{{ $event->venue->name }}</span><span><i class="ti ti-clock"></i>{{ $game->scheduled_starts_at && $game->scheduled_ends_at ? $game->scheduled_starts_at->format('H:i').'–'.$game->scheduled_ends_at->format('H:i') : 'Время на игру не задано' }}</span></div>
            </header>

            <section class="game-scoreboard" data-game-scoreboard data-score-url="{{ route('events.game.score', $legacyIdentifier) }}" data-image-upload-surface>
                @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем счёт…'])
                <div class="game-scoreboard__team is-a"><i class="ti ti-shirt-sport"></i><strong>{{ $sideA?->display_name ?: 'Команда A' }}</strong></div>
                <div class="game-scoreboard__score"><b data-game-visible-score="A">{{ $sideA?->score ?? 0 }}</b><span>:</span><b data-game-visible-score="B">{{ $sideB?->score ?? 0 }}</b><small>{{ $statisticsConfirmed ? 'Итоговый счёт' : 'Текущий счёт' }}</small></div>
                <div class="game-scoreboard__team is-b"><i class="ti ti-shirt-sport"></i><strong>{{ $sideB?->display_name ?: 'Команда B' }}</strong></div>
                @if($canManageScore && !$statisticsConfirmed && !$isCancelled)<button class="btn btn--secondary btn--sm game-scoreboard__edit" type="button" data-game-score-open><i class="ti ti-scoreboard"></i>Установить счёт</button>@endif
                @if($canUpdate && !$statisticsConfirmed && !$isCancelled)<details class="game-control-editor"><summary class="btn btn--secondary btn--sm"><i class="ti ti-pencil"></i>Редактировать</summary><form method="POST" action="{{ route('events.game.update', $legacyIdentifier) }}" class="row g-3">@csrf @method('PUT')<div class="col-12"><label class="form-label">Название</label><input class="form-control" name="title" value="{{ $title }}" required></div><input type="hidden" name="has_scheduled_time" value="{{ (int) ($game->scheduled_starts_at !== null) }}"><input type="hidden" name="starts_at" value="{{ $game->scheduled_starts_at?->format('H:i') }}"><input type="hidden" name="ends_at" value="{{ $game->scheduled_ends_at?->format('H:i') }}"><input type="hidden" name="side_a_size" value="{{ $game->side_a_size }}"><input type="hidden" name="side_b_size" value="{{ $game->side_b_size }}"><input type="hidden" name="scoring_type" value="{{ $game->scoring_type->value }}"><div class="col-md-6"><label class="form-label">Команда A</label><input class="form-control" name="side_a_name" value="{{ $sideA?->display_name }}"></div><div class="col-md-6"><label class="form-label">Команда B</label><input class="form-control" name="side_b_name" value="{{ $sideB?->display_name }}"></div><div class="col-12"><button class="btn btn--primary btn--sm">Сохранить</button></div></form></details>@endif
            </section>

            <form method="POST" action="{{ route('events.game.statistics', $legacyIdentifier) }}" data-game-live-statistics data-scoring-type="{{ $game->scoring_type->value }}" data-image-upload-surface>
                <h2 class="visually-hidden">Статистика игроков</h2>
                @csrf @method('PATCH')
                @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем статистику…'])
                <div class="alert" data-game-live-message role="status" aria-live="polite" hidden></div>
                <input type="hidden" name="scores[A]" value="{{ $sideA?->score ?? 0 }}" data-game-score-input="A"><input type="hidden" name="scores[B]" value="{{ $sideB?->score ?? 0 }}" data-game-score-input="B">
                @foreach(['A', 'B'] as $slot)
                    @php $side = $sides->get($slot); @endphp
                    <section class="game-team-panel" data-game-side="{{ $slot }}">
                        <header><span><i class="ti ti-shirt-sport"></i><strong>{{ $side?->display_name ?: 'Команда '.$slot }}</strong><small>· {{ $roster->get($side?->id, collect())->count() }} игрока</small></span>@if($canManageRoster && !$statisticsConfirmed && !$isCancelled)<button class="btn btn--secondary btn--sm" type="button" data-roster-editor-open><i class="ti ti-users-cog"></i>Управление составом</button>@endif</header>
                        <div class="game-team-panel__players">
                            @forelse($roster->get($side?->id, collect()) as $entry)
                                @php $stat = $stats->get($entry->user_id); @endphp
                                <article class="game-live-player" data-game-player="{{ $entry->user_id }}" data-game-player-side="{{ $slot }}">
                                    <div class="game-live-player__identity">@if($entry->user->profile?->activeAvatar)<img src="{{ $entry->user->profile->activeAvatar->publicUrl() }}" alt="">@else<span>{{ mb_strtoupper(mb_substr($name($entry->user), 0, 2)) }}</span>@endif<strong>{{ $name($entry->user) }}</strong></div>
                                    <div class="game-live-player__actions">
                                        @if($canEnterLiveStatistics)
                                            <button type="button" data-game-shot-open><i class="ti ti-ball-basketball"></i>Бросок</button>
                                            <button type="button" data-game-stat-increment="assists"><i class="ti ti-hand-click"></i>Передача</button>
                                            <button type="button" data-game-stat-increment="defensive_rebounds"><i class="ti ti-jump-rope"></i>Подбор</button>
                                            <button type="button" data-game-stat-increment="steals"><i class="ti ti-shield"></i>Перехват</button>
                                            <button type="button" data-game-stat-increment="fouls"><i class="ti ti-hand-stop"></i>Фол</button>
                                            <button type="button" class="is-more" data-game-inline-open aria-label="Изменить все показатели"><i class="ti ti-dots"></i></button>
                                        @endif
                                    </div>
                                    <strong class="game-live-player__points"><span data-game-player-points>{{ $stat?->points($game->scoring_type) ?? 0 }}</span> очк.</strong>
                                    @foreach($statisticsFields as $field => $definition)<input type="hidden" name="players[{{ $entry->user_id }}][{{ $field }}]" value="{{ $stat?->{$field} ?? 0 }}" data-game-stat-field="{{ $field }}" data-stat-label="{{ $definition['label'] }}">@endforeach
                                </article>
                            @empty <p>Состав пока не указан.</p> @endforelse
                        </div>
                    </section>
                @endforeach
            </form>

            @if($canManageRoster && !$statisticsConfirmed && !$isCancelled)
                <section class="game-roster-editor" data-roster-editor hidden data-image-upload-surface>
                    @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем состав…'])
                    <form method="POST" action="{{ route('events.game.roster', $legacyIdentifier) }}" data-game-roster-ajax>@csrf @method('PATCH')<div class="game-roster-grid">@foreach(['A', 'B'] as $slot)@php $side = $sides[$slot]; $selected = $roster->get($side->id, collect())->pluck('user_id'); $candidates = $isEmbeddedGame ? $parentCandidates : $side->team->memberships->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE)->map(fn ($membership) => $membership->user)->keyBy('id'); @endphp<fieldset class="game-side-card"><legend>{{ $side->display_name }}</legend>@foreach($candidates as $candidate)<label class="form-check"><input type="checkbox" name="side_{{ strtolower($slot) }}_user_ids[]" value="{{ $candidate->id }}" @checked($selected->contains($candidate->id))><span>{{ $name($candidate) }}</span></label>@endforeach</fieldset>@endforeach</div><div class="game-roster-editor__actions"><button class="btn btn--primary btn--sm">Сохранить состав</button><button class="btn btn--secondary btn--sm" type="button" data-roster-editor-close>Отмена</button></div></form>
                </section>
            @endif

            @if(!$isCompleted && !$isCancelled && ($canComplete || $canEnterLiveStatistics))
                <div class="game-lifecycle-actions">
                    @if($canComplete)<button class="btn btn--danger" type="button" data-game-cancel data-cancel-url="{{ route('events.game.cancel', $legacyIdentifier) }}"><i class="ti ti-circle-x"></i>Отменить игру</button>@endif
                    @if($canComplete && $canEnterLiveStatistics)<button class="game-complete-button" type="button" data-game-review-open><i class="ti ti-circle-check"></i>Завершить игру</button>@endif
                </div>
            @endif
        </div>
    </section>

    @component('theme::partials.modal.layout', ['id' => 'game-shot-modal'])
        <form data-game-shot-form><h2 class="modal_title" id="modal-title-game-shot-modal">Добавить бросок</h2><fieldset class="game-shot-range"><legend class="form-label">Тип броска</legend><label><input type="radio" name="range" value="close" checked><span>Ближний</span></label><label><input type="radio" name="range" value="mid"><span>Средний</span></label><label><input type="radio" name="range" value="three"><span>Трёхочковый</span></label></fieldset>@include('theme::partials.forms.toggle', ['id' => 'game-shot-made', 'name' => 'made', 'title' => 'Попадание', 'description' => 'По умолчанию бросок считается промахом.', 'checked' => false])<button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-score-modal'])
        <form data-game-score-form><h2 class="modal_title" id="modal-title-game-score-modal">Установить счёт</h2><div class="row g-3"><label class="field col-6"><span class="form-label">{{ $sideA?->display_name }}</span><input class="form-control" type="number" min="0" max="999" name="scores[A]" value="{{ $sideA?->score ?? 0 }}"></label><label class="field col-6"><span class="form-label">{{ $sideB?->display_name }}</span><input class="form-control" type="number" min="0" max="999" name="scores[B]" value="{{ $sideB?->score ?? 0 }}"></label></div><button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-inline-statistics-modal'])
        <form data-game-inline-form><h2 class="modal_title" id="modal-title-game-inline-statistics-modal">Показатели игрока</h2><div class="game-inline-statistics-fields" data-game-inline-fields></div><button class="btn btn--primary" type="submit">Сохранить</button></form>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'game-final-review-modal', 'dialogClass' => 'game-final-review-modal__dialog'])
        <div data-game-final-review data-complete-url="{{ route('events.game.statistics.complete', $legacyIdentifier) }}" data-image-upload-surface>@include('theme::partials.image-upload-loading', ['text' => 'Завершаем игру…'])<h2 class="modal_title" id="modal-title-game-final-review-modal">Итоги игры</h2><p>Проверьте счёт и показатели. После подтверждения они будут зафиксированы.</p><div class="game-final-review__score"><strong data-review-score="A">0</strong><span>:</span><strong data-review-score="B">0</strong></div><div data-game-review-table></div><div class="game-final-review__actions"><button class="btn btn--secondary" type="button" data-game-recalculate><i class="ti ti-calculator"></i>Пересчитать</button><button class="btn btn--primary" type="button" data-game-complete-confirm><i class="ti ti-circle-check"></i>Подтвердить и завершить</button></div></div>
    @endcomponent
@endsection
