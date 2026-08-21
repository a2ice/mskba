@php($tournament = $tournament ?? null)
@php($editing = $tournament !== null)
@php($structuralSettingsLocked = (bool) ($structuralSettingsLocked ?? false))
@php($recruitmentSettingLocked = (bool) ($recruitmentSettingLocked ?? false))
@php($admissionSettingLocked = (bool) ($admissionSettingLocked ?? false))
@php($datesFullyLocked = (bool) ($datesFullyLocked ?? false))
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
<div class="row g-3">
    <div class="col-12"><label class="form-label" for="tournamentTitle">Название</label><input id="tournamentTitle" class="form-control" name="title" value="{{ old('title', $tournament->title ?? '') }}" maxlength="150" required>@error('title')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
    <div class="col-12"><label class="form-label" for="tournamentAlias">Алиас ссылки</label><input id="tournamentAlias" class="form-control" name="alias" value="{{ old('alias', $tournament->alias ?? '') }}" maxlength="180" placeholder="Сформируется из названия" @readonly($editing)><p class="form-text">{{ $editing ? 'Алиас зафиксирован после создания, чтобы публичная ссылка не менялась.' : 'Уникальность не требуется: в ссылке также используется ID.' }}</p></div>
    <div class="col-md-6"><label class="form-label" for="tournamentStartsOn">Дата начала</label>@if($datesFullyLocked)<input type="hidden" name="starts_on" value="{{ $tournament->starts_on->format('Y-m-d') }}">@endif<input id="tournamentStartsOn" class="form-control" type="date" name="starts_on" value="{{ old('starts_on', $tournament?->starts_on?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required @disabled($datesFullyLocked)>@error('starts_on')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
    <div class="col-md-6"><label class="form-label" for="tournamentEndsOn">Дата окончания</label>@if($datesFullyLocked)<input type="hidden" name="ends_on" value="{{ $tournament->ends_on?->format('Y-m-d') }}">@endif<input id="tournamentEndsOn" class="form-control" type="date" name="ends_on" value="{{ old('ends_on', $tournament?->ends_on?->format('Y-m-d') ?? '') }}" @disabled($datesFullyLocked)>@error('ends_on')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
    <div class="col-12">
        @include('theme::partials.venues.predictive-selector', [
            'id' => 'tournamentDefaultVenue',
            'name' => 'default_venue_id',
            'label' => 'Площадка по умолчанию',
            'selectedVenue' => $tournament?->defaultVenue,
            'confirmedOnly' => true,
            'required' => false,
            'showBookingScope' => false,
        ])
        <p class="form-text">Будет подставляться при назначении новых матчей. В конкретной игре площадку можно изменить. Выбор здесь не создаёт бронь.</p>
    </div>
    <div class="col-12"><label class="form-label" for="tournamentFormat">Общий формат</label>@if($structuralSettingsLocked)<input type="hidden" name="format" value="{{ $tournament->format?->value }}"><input id="tournamentFormat" class="form-control" value="{{ $tournament->format?->label() ?? 'Не установлен' }}" readonly><p class="form-text">Чтобы изменить формат, сначала {{ $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'возобновите набор команд' : 'расформируйте команды' }}.</p>@else<select id="tournamentFormat" class="form-select" name="format" data-tournament-format><option value="">Не установлен</option>@foreach($formats as $format)<option value="{{ $format->value }}" @selected(old('format', $tournament->format?->value ?? '') === $format->value)>{{ $format->label() }}</option>@endforeach</select>@endif</div>
    <div class="col-12"><label class="form-label" for="tournamentRecruitmentMode">Как формируются участники</label>@if($recruitmentSettingLocked)<input type="hidden" name="recruitment_mode" value="{{ $tournament->recruitment_mode->value }}"><input id="tournamentRecruitmentMode" class="form-control" value="{{ $tournament->recruitment_mode->label() }}" readonly><p class="form-text">Зафиксировано после первой заявки или приглашения.</p>@else<select id="tournamentRecruitmentMode" class="form-select" name="recruitment_mode" required data-tournament-recruitment-mode>@foreach($recruitmentModes as $mode)<option value="{{ $mode->value }}" @selected(old('recruitment_mode', $tournament->recruitment_mode?->value ?? \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value) === $mode->value)>{{ $mode->label() }}</option>@endforeach</select><p class="form-text">Для 1×1 всегда используются отдельные игроки. После первой заявки режим не меняется.</p>@endif</div>
    <div class="col-12" data-tournament-unconfirmed-setting>
        @if($admissionSettingLocked)
            <input type="hidden" name="accepts_unconfirmed_participants" value="{{ $tournament?->accepts_unconfirmed_participants ? 1 : 0 }}">
            <label class="form-label">Принимать заявки от неподтверждённых пользователей</label>
            <input class="form-control" value="{{ $tournament?->accepts_unconfirmed_participants ? 'Разрешено' : 'Запрещено' }}" readonly>
            <p class="form-text">Настройка зафиксирована после закрытия приёма или формирования команд.</p>
        @else
        @include('theme::partials.forms.toggle', [
            'id' => 'tournamentAcceptsUnconfirmedParticipants',
            'name' => 'accepts_unconfirmed_participants',
            'title' => 'Принимать заявки от неподтверждённых пользователей',
            'description' => 'Если выключено, подать персональную заявку смогут только пользователи с подтверждённым аккаунтом.',
            'checked' => (bool) old('accepts_unconfirmed_participants', $tournament?->accepts_unconfirmed_participants ?? false),
            'wrapperClass' => 'mb-0',
        ])
        @endif
    </div>
    <div class="col-12">
        <label class="form-label" for="tournamentCover">Обложка</label>

        @if($tournament?->cover)<div class="event-image"><img src="{{ $tournament->cover->publicUrl() }}" alt="{{ $tournament->title }}" style="max-width:100%;height:auto;max-height:100px;border-radius:16px;margin-bottom:24px"></div>@endif

        <input id="tournamentCover" class="form-control" type="file" name="cover" accept="image/*">@if($tournament?->cover)<p class="form-text">Новый файл заменит текущую обложку.</p>@endif
    </div>
    <div class="col-12"><label class="form-label" for="tournamentShortDescription">Краткое описание</label><textarea id="tournamentShortDescription" class="form-control" name="short_description" rows="3" maxlength="1000">{{ old('short_description', $tournament->short_description ?? '') }}</textarea></div>
    <div class="col-12"><label class="form-label" for="tournamentFullDescription">Полное описание</label><textarea id="tournamentFullDescription" class="form-control" name="full_description" rows="8" maxlength="20000">{{ old('full_description', $tournament->full_description ?? '') }}</textarea></div>
</div>
<button class="btn btn--primary mt-4" type="submit">{{ $editing ? 'Сохранить' : 'Создать турнир' }}</button>
