@extends('theme::layouts.section-sidebar', [
    'title' => 'Новая команда', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Новая команда', 'contentSubtitle' => 'Создатель становится владельцем и отвечает за состав.',
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команды</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.index') }}">Все команды</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.create') }}">Создать</a></li>
</ul></div>
@endsection
@section('section-content')
<form method="POST" action="{{ route('teams.store') }}" data-team-name-form data-team-name-suggestion-url="{{ route('teams.name-suggestion') }}">@csrf
<div class="form-group field mb-3"><label class="form-label">Название</label><div class="team-name-field__control"><input class="form-control" name="name" value="{{ old('name') }}" required maxlength="140" data-team-name-input title="Названия команд могут совпадать. Если активная команда с таким названием уже существует, система добавит порядковый номер, например «Название №2»." data-tooltip-variant="question"></div><p class="form-hint text-warning" data-team-name-warning hidden></p>@error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
<fieldset class="mb-3"><legend class="form-label">Тип команды</legend><p class="form-hint">Можно выбрать несколько дисциплин. Размер общего состава не ограничивается этим выбором.</p><div class="d-flex flex-wrap gap-3">@foreach($sportTypes as $type)<label class="form-check"><input class="form-check-input" type="checkbox" name="sport_types[]" value="{{ $type->value }}" @checked(in_array($type->value, old('sport_types', ['basketball']), true))><span class="form-check-label">{{ $type->label() }}</span></label>@endforeach</div>@error('sport_types')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</fieldset>
<div class="form-group field mb-4"><label class="form-label">Описание</label><textarea class="form-control" name="description" rows="6" maxlength="5000">{{ old('description') }}</textarea></div>
<button class="btn btn--primary">Создать команду</button>
</form>
@endsection
