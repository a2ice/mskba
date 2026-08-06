@extends('theme::layouts.section-sidebar', [
    'title' => 'Основные настройки', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Основные настройки', 'contentSubtitle' => $team->name,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>
<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>
@if($canManageJoinRequests)<li class="nav-item"><a class="nav-link" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>@endif
</ul></div>
@endsection
@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
<section class="section-card mb-4">
<h2>Логотип</h2>
<form method="POST" action="{{ route('teams.logo.store', $team->routeIdentifier()) }}" enctype="multipart/form-data" class="team-logo-upload-form" data-image-upload data-image-upload-auto-submit>@csrf
<label class="team-logo-upload" for="team-logo-input" data-image-upload-surface>
<img class="team-logo-upload__image" src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt="Логотип команды {{ $team->name }}">
<span class="team-logo-upload__overlay" aria-hidden="true"><i class="ti ti-camera"></i></span>
@include('theme::partials.image-upload-loading', ['text' => 'Загружаем логотип…'])
<span class="visually-hidden">{{ $team->logo ? 'Заменить логотип команды' : 'Добавить логотип команды' }}</span>
</label>
<input id="team-logo-input" class="visually-hidden" type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
<p class="form-hint team-logo-upload__hint">JPEG, PNG или WebP · до 5 МБ. Изображение будет уменьшено до 500 пикселей.</p>
@error('logo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</form>
@if($team->logo)<form class="team-logo-delete-form" method="POST" action="{{ route('teams.logo.destroy', $team->routeIdentifier()) }}" onsubmit="return confirm('Удалить логотип команды?')">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm" type="submit">Удалить логотип</button></form>@endif
</section>

<form method="POST" action="{{ route('teams.update', $team->routeIdentifier()) }}" class="section-card mb-4">@csrf @method('PUT')
<h2>Данные команды</h2>
<div class="team-data-readonly mb-3"><span class="form-label">Название</span><strong>{{ $team->name }}</strong><input type="hidden" name="name" value="{{ $team->base_name ?? $team->name }}"></div>
@if($canModerateStatus)<input type="hidden" name="status" value="{{ $team->status->value }}">@endif
<div class="form-group field mb-3"><label class="form-label">Описание</label><textarea class="form-control" name="description" rows="4">{{ old('description',$team->description) }}</textarea></div>
@php($selectedSportTypes = old('sport_types', $team->sportProfiles->pluck('sport_type.value')->all()))
<fieldset class="mb-3"><legend class="form-label team-form-legend"><span>Тип команды</span><button class="ui-tooltip-trigger" type="button" aria-label="Подсказка о типе команды" data-tooltip="Можно выбрать несколько дисциплин. Размер общего состава не ограничивается этим выбором.">?</button></legend><div class="d-flex flex-wrap gap-3">@foreach($sportTypes as $type)<label class="form-check"><input class="form-check-input" type="checkbox" name="sport_types[]" value="{{ $type->value }}" @checked(in_array($type->value, $selectedSportTypes, true))><span class="form-check-label">{{ $type->label() }}</span></label>@endforeach</div>@error('sport_types')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</fieldset>
<button class="btn btn--primary">Сохранить</button></form>

<section class="section-card mb-4">
    <h2>Настройки</h2>
    <form method="POST" action="{{ route('teams.settings.applications.update', $team->routeIdentifier()) }}">
        @csrf @method('PATCH')
        @include('theme::partials.forms.toggle', [
            'id' => 'team-accepts-join-requests',
            'name' => 'accepts_join_requests',
            'title' => 'Принимать заявки на вступление в команду',
            'checked' => old('accepts_join_requests', $team->accepts_join_requests),
            'wrapperClass' => 'mb-3',
        ])
        <p class="form-hint mb-3">Когда настройка включена, пользователи могут подать заявку с публичной страницы команды.</p>
        <button class="btn btn--primary" type="submit">Сохранить настройки</button>
    </form>
</section>

@if($canDeleteTeam)
<section class="section-card mb-4"><h2>Удаление команды</h2><p class="form-hint">Команда будет перенесена в черновики. Удаление недоступно, пока команда связана с мероприятием или турниром.</p>
<form class="mt-2" method="POST" action="{{ route('teams.destroy', $team->routeIdentifier()) }}" onsubmit="return confirm('Вы уверены, что хотите удалить команду? Команда будет перенесена в черновики.')">@csrf @method('DELETE')<button class="btn btn--danger" type="submit">Удалить команду</button></form></section>
@endif
@endsection
