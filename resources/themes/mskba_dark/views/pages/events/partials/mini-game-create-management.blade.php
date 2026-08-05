@php
    $candidateCount = $confirmedParticipants->count();
    $defaultSideSize = max(1, min(3, intdiv($candidateCount, 2)));
@endphp

@if($candidateCount < 2)
    <div class="alert alert-info">Для создания мини-игры нужны хотя бы два подтверждённых участника.</div>
@else
    <details class="event-mini-games__create mt-3">
        <summary class="btn btn--primary btn--sm">Добавить мини-игру</summary>
        <form method="POST" action="{{ route('events.games.store', $event->routeIdentifier()) }}" class="mt-3">
            @csrf
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="management-mini-game-title">Название</label>
                    <input id="management-mini-game-title" class="form-control" name="title" value="{{ old('title', 'Мини-игра') }}" maxlength="255" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="management-mini-game-scoring">Правила подсчёта</label>
                    <select id="management-mini-game-scoring" class="form-select" name="scoring_type">
                        @foreach(\App\Modules\Event\Domain\Enums\GameScoringTypeEnum::cases() as $scoringType)
                            <option value="{{ $scoringType->value }}" @selected(old('scoring_type', 'streetball') === $scoringType->value)>{{ $scoringType->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="management-mini-game-side-a-size">Игроков A</label>
                    <input id="management-mini-game-side-a-size" class="form-control" type="number" name="side_a_size" value="{{ old('side_a_size', $defaultSideSize) }}" min="1" max="6" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="management-mini-game-side-b-size">Игроков B</label>
                    <input id="management-mini-game-side-b-size" class="form-control" type="number" name="side_b_size" value="{{ old('side_b_size', $defaultSideSize) }}" min="1" max="5" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="management-mini-game-side-a-name">Название команды A</label>
                    <input id="management-mini-game-side-a-name" class="form-control" name="side_a_name" value="{{ old('side_a_name', 'Команда A') }}" maxlength="80" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="management-mini-game-side-b-name">Название команды B</label>
                    <input id="management-mini-game-side-b-name" class="form-control" name="side_b_name" value="{{ old('side_b_name', 'Команда B') }}" maxlength="80" required>
                </div>
            </div>

            <div class="game-roster-grid mt-3">
                @foreach(['a' => 'Команда A', 'b' => 'Команда B'] as $slot => $label)
                    <fieldset class="game-side-card">
                        <legend>{{ $label }}</legend>
                        @foreach($confirmedParticipants as $participant)
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="side_{{ $slot }}_user_ids[]" value="{{ $participant->user_id }}" @checked(in_array($participant->user_id, old('side_'.$slot.'_user_ids', []), false))>
                                <span class="form-check-label">{{ $name($participant) }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            <p class="form-hint">Один участник не может одновременно входить в обе команды. Количество выбранных игроков должно соответствовать указанному формату.</p>
            <button class="btn btn--primary btn--sm" type="submit">Создать мини-игру</button>
        </form>
    </details>
@endif
