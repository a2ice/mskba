@extends('theme::layouts.section-sidebar', [
    'title' => 'Управление командой', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Управление командой', 'contentSubtitle' => $team->name,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Управление</a></li>
</ul></div>
@endsection
@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
<section class="section-card mb-4">
<h2>Логотип</h2>
<p class="form-hint">JPEG, PNG или WebP · до 5 МБ. Изображение будет уменьшено до 500 пикселей по большей стороне.</p>
@if($team->logo)
<div class="team-logo-editor">
<img class="team-logo team-logo--large" src="{{ $team->logo->publicUrl() }}" alt="Логотип команды {{ $team->name }}">
<form method="POST" action="{{ route('teams.logo.destroy', $team->routeIdentifier()) }}" onsubmit="return confirm('Удалить логотип команды?')">@csrf @method('DELETE')
<button class="btn btn--secondary btn--sm" type="submit">Удалить</button></form>
</div>
@endif
<form method="POST" action="{{ route('teams.logo.store', $team->routeIdentifier()) }}" enctype="multipart/form-data">
@csrf
<input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
@error('logo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
<button class="btn btn--primary btn--sm mt-3" type="submit">{{ $team->logo ? 'Заменить логотип' : 'Добавить логотип' }}</button>
</form>
</section>
<form method="POST" action="{{ route('teams.update', $team->routeIdentifier()) }}" class="section-card mb-4" data-team-name-form data-team-name-suggestion-url="{{ route('teams.name-suggestion') }}" data-team-name-except="{{ $team->id }}">@csrf @method('PUT')
<h2>Данные команды</h2><div class="form-group field mb-3"><label class="form-label">Название</label><input class="form-control" name="name" value="{{ old('name',$team->base_name ?? $team->name) }}" required maxlength="140" data-team-name-input><p class="form-hint">Названия команд могут совпадать. Если активная команда с таким названием уже существует, система добавит порядковый номер, например «Название №2».</p><p class="form-hint text-warning" data-team-name-warning hidden></p>@error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
<div class="form-group field mb-3"><label class="form-label">Описание</label><textarea class="form-control" name="description" rows="4">{{ old('description',$team->description) }}</textarea></div>
@php($selectedSportTypes = old('sport_types', $team->sportProfiles->pluck('sport_type.value')->all()))
<fieldset class="mb-3"><legend class="form-label">Тип команды</legend><p class="form-hint">Можно выбрать несколько дисциплин. Размер общего состава не ограничивается этим выбором.</p><div class="d-flex flex-wrap gap-3">@foreach($sportTypes as $type)<label class="form-check"><input class="form-check-input" type="checkbox" name="sport_types[]" value="{{ $type->value }}" @checked(in_array($type->value, $selectedSportTypes, true))><span class="form-check-label">{{ $type->label() }}</span></label>@endforeach</div>@error('sport_types')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</fieldset>
@if($canModerateStatus)<div class="form-group field mb-3"><label class="form-label">Статус</label>
<select class="form-select" name="status">@foreach(\App\Modules\Team\Domain\Enums\TeamStatusEnum::cases() as $status)<option value="{{ $status->value }}" @selected($team->status===$status)>{{ $status->label() }}</option>@endforeach</select>
</div>@endif
<button class="btn btn--primary">Сохранить</button></form>
@if($canDeleteTeam)
<section class="section-card mb-4"><h2>Удаление команды</h2><p class="form-hint">Команда будет перенесена в черновики. Удаление недоступно, пока команда связана с мероприятием или турниром.</p>
<form class="mt-2" method="POST" action="{{ route('teams.destroy', $team->routeIdentifier()) }}" onsubmit="return confirm('Вы уверены, что хотите удалить команду? Команда будет перенесена в черновики.')">@csrf @method('DELETE')<button class="btn btn--danger" type="submit">Удалить команду</button></form></section>
@endif
<div class="section-card"><h2>Состав и приглашения</h2>
<p class="form-hint">Основные и запасные составы, приглашения, роли и капитан управляются на странице команды.</p>
<a class="btn btn--secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}#team-lineups-title">Перейти к составу</a></div>
@endsection
