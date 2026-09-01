@extends('theme::layouts.section-sidebar', [
    'title' => 'Основные настройки', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Основные настройки', 'contentSubtitle' => $team->name,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>
@if($canManageMembersAndRoster)<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>@endif
@if($canManageJoinRequests)<li class="nav-item"><a class="nav-link" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>@endif
@if($canManageVenues)<li class="nav-item"><a class="nav-link" href="{{ route('teams.venues.index', $team->routeIdentifier()) }}">Площадки</a></li>@endif
@if($canManageHiring)<li class="nav-item"><a class="nav-link" href="{{ route('teams.hiring.index', $team->routeIdentifier()) }}">Набор</a></li>@endif
</ul></div>
@endsection
@section('section-content')
@php
    $savedTeamColors = $team->colors ?? [];
    $pageHomePrimary = data_get($savedTeamColors, 'home_primary');
    $pageHomeSecondary = data_get($savedTeamColors, 'home_secondary');
    $pageHomePrimaryEffective = $pageHomePrimary ?: $pageHomeSecondary;
    $pageHomeSecondaryEffective = $pageHomeSecondary ?: $pageHomePrimary;
    $teamColorRgba = static function (?string $hex): ?string {
        if (!$hex) {
            return null;
        }

        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return null;
        }

        return sprintf(
            'rgba(%d, %d, %d, .65)',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    };
    $pageHomePrimaryRgba = $teamColorRgba($pageHomePrimaryEffective);
    $pageHomeSecondaryRgba = $teamColorRgba($pageHomeSecondaryEffective);
@endphp
<style>
@if($pageHomePrimaryRgba)
.site-content {
    background:
        linear-gradient(180deg, var(--page) 0 110px, transparent 170px),
        linear-gradient(90deg, {{ $pageHomePrimaryRgba }} 0%, {{ $pageHomeSecondaryRgba }} 50%, {{ $pageHomePrimaryRgba }} 100%);
}
@endif
.team-color-control {
    display: flex;
    align-items: center;
    gap: 8px;
}
.team-color-picker {
    -webkit-appearance: none;
    appearance: none;
    width: 38px;
    min-width: 38px;
    height: 38px;
    padding: 3px;
    border: 1px solid var(--field-border);
    border-radius: 8px;
    background: var(--field);
    cursor: pointer;
}
.team-color-picker::-webkit-color-swatch-wrapper { padding: 0; }
.team-color-picker::-webkit-color-swatch { border: 0; border-radius: 5px; }
.team-color-picker::-moz-color-swatch { border: 0; border-radius: 5px; }
.team-color-picker.is-empty { opacity: .45; }
.team-color-value {
    min-width: 72px;
    color: var(--muted);
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    line-height: 1.2;
}
.team-color-reset {
    display: inline-grid;
    place-items: center;
    width: 32px;
    min-width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--line-strong);
    border-radius: 7px;
    color: var(--text);
    background: transparent;
    font: 500 20px/1 var(--font-ui);
    cursor: pointer;
}
.team-color-reset:hover:not(:disabled),
.team-color-reset:focus-visible:not(:disabled) {
    border-color: var(--accent-hover);
    color: var(--accent-text);
    outline: none;
}
.team-color-reset:disabled {
    opacity: .3;
    cursor: default;
}
</style>
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

@php
    $teamColors = old('colors', $team->colors ?? []);
    $teamColorOptions = [
        'home_primary' => 'Основной домашний',
        'home_secondary' => 'Дополнительный домашний',
        'away_primary' => 'Основной гостевой',
        'away_secondary' => 'Дополнительный гостевой',
    ];
@endphp
<section class="section-card mb-4">
    <h2>Цвета команды</h2>
    <p class="form-hint mb-3">Цвета формы и визуального оформления команды. Каждый цвет необязателен.</p>
    <form method="POST" action="{{ route('teams.settings.colors.update', $team->routeIdentifier()) }}">
        @csrf @method('PATCH')
        <div class="row g-3">
            @foreach($teamColorOptions as $key => $label)
                @php($color = data_get($teamColors, $key))
                <div class="col-12 col-md-6">
                    <label class="form-label" for="team-color-{{ $key }}">{{ $label }}</label>
                    <div class="team-color-control" data-team-color>
                        <input
                            id="team-color-{{ $key }}"
                            @class(['team-color-picker', 'is-empty' => !$color])
                            type="color"
                            value="{{ $color ?: '#000000' }}"
                            aria-label="{{ $label }}"
                            oninput="const root=this.closest('[data-team-color]');root.querySelector('input[type=hidden]').value=this.value;root.querySelector('[data-team-color-value]').textContent=this.value.toUpperCase();root.querySelector('[data-team-color-reset]').disabled=false;this.classList.remove('is-empty');"
                        >
                        <input type="hidden" name="colors[{{ $key }}]" value="{{ $color }}">
                        <span class="team-color-value" data-team-color-value>{{ $color ? strtoupper($color) : 'Не задан' }}</span>
                        <button
                            class="team-color-reset"
                            type="button"
                            title="Сбросить цвет"
                            data-tooltip-variant="title"
                            data-tooltip-icon
                            aria-label="Сбросить цвет: {{ $label }}"
                            data-team-color-reset
                            @disabled(!$color)
                            onclick="const root=this.closest('[data-team-color]');root.querySelector('input[type=hidden]').value='';root.querySelector('[data-team-color-value]').textContent='Не задан';root.querySelector('input[type=color]').classList.add('is-empty');this.disabled=true;"
                        ><span aria-hidden="true">×</span></button>
                    </div>
                    @error('colors.'.$key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>
        <button class="btn btn--primary mt-3" type="submit">Применить</button>
    </form>
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
        @include('theme::partials.forms.toggle', [
            'id' => 'team-accepts-competition-invitations',
            'name' => 'accepts_competition_invitations',
            'title' => 'Разрешать приглашения команды в игры и турниры',
            'checked' => old('accepts_competition_invitations', $team->accepts_competition_invitations),
            'wrapperClass' => 'mb-3',
        ])
        <p class="form-hint mb-3">Если выключить настройку, команда не будет показываться организаторам в списках и поиске для приглашения. Представитель команды по-прежнему сможет сам подать заявку на подходящую игру или турнир.</p>
        <button class="btn btn--primary" type="submit">Сохранить настройки</button>
    </form>
</section>

@if($canDeleteTeam)
<section class="section-card mb-4">
    <h2>Перевод в черновик</h2>
    <p class="form-hint">Команда будет скрыта из публичного списка, но её состав, приглашения, заявки и история сохранятся. Перевод недоступен, пока команда связана с мероприятием или турниром.</p>
    <form class="mt-2" method="POST" action="{{ route('teams.destroy', $team->routeIdentifier()) }}" onsubmit="return confirm('Перевести команду в черновик? Она будет скрыта из публичного списка, но данные сохранятся.')">@csrf @method('DELETE')<button class="btn btn--danger" type="submit">Перевести в черновик</button></form>
</section>
@elseif($team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::DRAFT)
<section class="section-card mb-4">
    <h2>Восстановление команды</h2>
    <p class="form-hint">После восстановления команда снова появится в публичном списке. Сценарий восстановления пока находится в разработке.</p>
    <button class="btn btn--primary" type="button" disabled aria-disabled="true">Восстановить команду</button>
</section>
@endif
@endsection
