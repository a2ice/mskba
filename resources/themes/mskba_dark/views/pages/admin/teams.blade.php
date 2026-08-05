@php $title = 'Команды'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Системный просмотр команд, владельцев, состава и состояния.',
])

@section('section-content')
<form method="GET" action="{{ route('admin.teams') }}" class="admin-filter">
    <label class="admin-filter__field"><span class="admin-filter__label">Поиск</span><input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название команды"></label>
    <label class="admin-filter__field"><span class="admin-filter__label">Статус</span><select class="form-select" name="status"><option value="">Все</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></label>
    <label class="admin-filter__field"><span class="admin-filter__label">Тип</span><select class="form-select" name="temporary"><option value="">Все</option><option value="no" @selected(($filters['temporary'] ?? '') === 'no')>Постоянные</option><option value="yes" @selected(($filters['temporary'] ?? '') === 'yes')>Временные</option></select></label>
    <div class="admin-filter__actions"><button type="submit" class="btn btn--primary btn--sm">Фильтр</button><a href="{{ route('admin.teams') }}" class="btn btn--secondary btn--sm">Сброс</a></div>
</form>

@if($teams->isEmpty())
    <div class="admin-empty">Команды не найдены.</div>
@else
<div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>ID</th><th>Название</th><th>Статус</th><th>Дисциплины</th><th>Участники</th><th>Создатель</th><th></th></tr></thead>
    <tbody>@foreach($teams as $team)
        @php($creator = $team->createdByActor?->user)
        <tr>
            <td>{{ $team->id }}</td>
            <td><strong>{{ $team->name }}</strong>@if($team->trashed())<br><span class="admin-badge">Удалена</span>@elseif($team->isTemporary())<br><span class="admin-badge">Временная</span>@endif</td>
            <td><span class="admin-badge">{{ $team->status->label() }}</span></td>
            <td>{{ $team->sportProfiles->map(fn($profile) => $profile->sport_type->label())->join(', ') ?: '—' }}</td>
            <td>{{ $team->active_memberships_count }}</td>
            <td>{{ $creator?->profile?->first_name ?: $creator?->username ?: '—' }}</td>
            <td><a class="btn btn--secondary btn--sm" href="{{ route('admin.teams.show', $team->routeIdentifier()) }}">Открыть</a></td>
        </tr>
    @endforeach</tbody>
</table></div>
@include('theme::partials.admin.pagination', ['paginator' => $teams])
@endif
@endsection
