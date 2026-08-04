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
<form method="POST" action="{{ route('teams.update', $team->routeIdentifier()) }}" class="section-card mb-4">@csrf @method('PUT')
<h2>Данные команды</h2><div class="form-group field mb-3"><label class="form-label">Название</label><input class="form-control" name="name" value="{{ old('name',$team->name) }}" required></div>
<div class="form-group field mb-3"><label class="form-label">Описание</label><textarea class="form-control" name="description" rows="4">{{ old('description',$team->description) }}</textarea></div>
<div class="form-group field mb-3"><label class="form-label">Статус</label><select class="form-select" name="status">@foreach(\App\Modules\Team\Domain\Enums\TeamStatusEnum::cases() as $status)<option value="{{ $status->value }}" @selected($team->status===$status)>{{ $status->label() }}</option>@endforeach</select></div>
<button class="btn btn--primary">Сохранить</button></form>
<div class="section-card"><h2>Состав</h2>
<p class="form-hint">Спортивная роль не заменяет права доступа. Капитаном и участником стартового состава может быть только игрок. Эти настройки используются как шаблон для новых игр.</p>
@foreach($team->memberships as $membership)
@php
    $memberName = trim(implode(' ', array_filter([$membership->user->profile?->first_name, $membership->user->profile?->last_name]))) ?: $membership->user->username;
    $memberType = $membership->member_type?->value ?? 'player';
@endphp
<div class="section-card section-card--nested mb-3">
<div class="d-flex justify-content-between align-items-center gap-3 mb-3">
<strong>{{ $memberName }}</strong>
<span>{{ \App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum::from($membership->access_level)->label() }}</span>
</div>
<form method="POST" action="{{ route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]) }}">@csrf @method('PUT')
<div class="row g-3 align-items-end">
<div class="col-md-5"><label class="form-label">Тип участника</label><select class="form-select" name="member_type">
@foreach(\App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::cases() as $type)<option value="{{ $type->value }}" @selected($memberType === $type->value)>{{ $type->label() }}</option>@endforeach
</select></div>
<div class="col-md-3"><label class="form-check"><input type="hidden" name="is_captain" value="0"><input class="form-check-input" type="checkbox" name="is_captain" value="1" @checked($membership->is_captain)><span class="form-check-label">Капитан</span></label></div>
<div class="col-md-4"><label class="form-check"><input type="hidden" name="is_default_starter" value="0"><input class="form-check-input" type="checkbox" name="is_default_starter" value="1" @checked($membership->is_default_starter)><span class="form-check-label">Старт по умолчанию</span></label></div>
</div>
<div class="d-flex justify-content-between align-items-center gap-3 mt-3"><button class="btn btn--secondary btn--sm" type="submit">Сохранить роль</button></form>
@if($membership->access_level !== 'owner')<form method="POST" action="{{ route('teams.members.destroy',[$team->routeIdentifier(),$membership]) }}">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm">Исключить</button></form>@endif</div>
</div>
@endforeach
<hr><form method="POST" action="{{ route('teams.members.store',$team->routeIdentifier()) }}">@csrf
<div class="row g-3"><div class="col-md-7"><label class="form-label">Пользователь</label><select class="form-select" name="user_id">@foreach($users as $user) @php($userName = trim(implode(' ', array_filter([$user->profile?->first_name, $user->profile?->last_name]))) ?: $user->username)<option value="{{ $user->id }}">{{ $userName }}</option>@endforeach</select></div>
<div class="col-md-5"><label class="form-label">Права в команде</label><select class="form-select" name="access_level">@foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></div></div>
<button class="btn btn--primary mt-3">Добавить или изменить</button></form></div>
@endsection
