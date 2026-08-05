@php
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
    use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
    use App\Modules\Event\Domain\Enums\EventStatusEnum;

    $allows = fn (EventResponsibilityPermissionEnum $permission): bool => $effectivePermissions->contains($permission->value);
    $name = static fn ($participant): string => trim(implode(' ', array_filter([
        $participant->user?->profile?->first_name,
        $participant->user?->profile?->last_name,
    ]))) ?: $participant->user?->username ?: 'Пользователь #'.$participant->user_id;
    $canManageParticipants = $allows(EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
    $canManageResponsibilities = $allows(EventResponsibilityPermissionEnum::MANAGE_RESPONSIBILITIES);
    $canUpdate = $allows(EventResponsibilityPermissionEnum::UPDATE_EVENT);
    $canCancel = $allows(EventResponsibilityPermissionEnum::CANCEL_EVENT)
        && ! in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true);
    $canManageResult = $allows(EventResponsibilityPermissionEnum::MANAGE_RESULT)
        || $allows(EventResponsibilityPermissionEnum::COMPLETE_EVENT);
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => 'Управление мероприятием',
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => 'Управление мероприятием',
    'contentSubtitle' => $event->title,
])

@section('section-sidebar')
<div class="section-sidebar-block">
    <h2 class="section-sidebar-block__title">Мероприятие</h2>
    <ul class="sidebar-nav nav flex-column">
        <li class="nav-item"><a class="nav-link" href="{{ route('events.show', $event->routeIdentifier()) }}">Публичная страница</a></li>
        <li class="nav-item active"><a class="nav-link active" href="{{ route('events.management', $event->routeIdentifier()) }}">Управление</a></li>
        @if($canUpdate)<li class="nav-item"><a class="nav-link" href="{{ route('events.edit', $event->routeIdentifier()) }}">Основные данные</a></li>@endif
    </ul>
</div>
@endsection

@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<section class="section-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div><span class="eyebrow">Рабочая область</span><h2>{{ $event->title }}</h2><p class="form-hint">Здесь находятся действия, изменяющие мероприятие или других участников. Личное участие остаётся на публичной странице.</p></div>
        <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $event->routeIdentifier()) }}">Посмотреть на сайте</a>
    </div>
</section>

@if($canManageParticipants)
<section class="section-card mb-4" id="event-participant-management" data-event-participant-management data-candidates-url="{{ route('events.participants.candidates', $event->routeIdentifier()) }}">
    <h2>Участники</h2>
    <form method="POST" action="{{ route('events.participants.manage.store', $event->routeIdentifier()) }}" data-event-participant-form class="mb-4">
        @csrf
        <label class="form-label" for="event-management-participant-search">Добавить пользователя</label>
        <div class="predictive-search__input-wrap event-participant-search">
            <input id="event-management-participant-search" class="form-control" type="search" autocomplete="off" placeholder="Имя или логин" data-event-participant-search>
            <input type="hidden" name="user_id" data-event-participant-user-id>
            <div class="predictive-search__results" data-event-participant-results hidden></div>
        </div>
        <button class="btn btn--primary btn--sm mt-2" type="submit">Добавить в список «Думают»</button>
    </form>

    @foreach([
        ['title' => 'Идут', 'items' => $confirmedParticipants],
        ['title' => 'Думают', 'items' => $tentativeParticipants],
        ['title' => 'Не идут', 'items' => $declinedParticipants],
    ] as $group)
        <div class="mb-4"><h3>{{ $group['title'] }}</h3>
            @forelse($group['items'] as $participant)
                <article class="section-card mb-2">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
                        <strong>{{ $name($participant) }}</strong>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach(['confirmed' => 'Идёт', 'tentative' => 'Думает', 'left' => 'Не идёт'] as $status => $label)
                                @if(($participant->status->value ?? $participant->status) !== $status)
                                <form method="POST" action="{{ route('events.participants.manage.status', [$event->routeIdentifier(), $participant->id]) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $status }}"><button class="btn btn--secondary btn--sm" type="submit">{{ $label }}</button></form>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </article>
            @empty<p class="form-hint">Список пуст.</p>@endforelse
        </div>
    @endforeach
</section>
@endif

@if($canManageResponsibilities)
<section class="section-card mb-4">
    <h2>Ответственные и права</h2>
    <p class="form-hint">Назначение начинает действовать после согласия участника.</p>
    @foreach($event->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED) as $participant)
        @php($responsibility = $participant->responsibility_status)
        <article class="section-card mb-3">
            <strong>{{ $name($participant) }}</strong>
            <p class="form-hint">{{ $responsibility?->label() ?? 'Не назначен' }}</p>
            @if($responsibility === null || $responsibility === EventResponsibilityStatusEnum::DECLINED)
                <form method="POST" action="{{ route('events.participants.responsibility.request', [$event->routeIdentifier(), $participant->id]) }}">@csrf
                    <input type="hidden" name="permissions_present" value="1">
                    <div class="d-flex flex-wrap gap-3 mb-2">@foreach(EventResponsibilityPermissionEnum::cases() as $permission)<label class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $permission->value }}"><span class="form-check-label">{{ $permission->label() }}</span></label>@endforeach</div>
                    <button class="btn btn--primary btn--sm" type="submit">Назначить</button>
                </form>
            @else
                <form method="POST" action="{{ route('events.participants.responsibility.permissions.update', [$event->routeIdentifier(), $participant->id]) }}">@csrf @method('PUT')
                    <div class="d-flex flex-wrap gap-3 mb-2">@foreach(EventResponsibilityPermissionEnum::cases() as $permission)<label class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked($participant->responsibilityPermissions->contains('permission', $permission->value))><span class="form-check-label">{{ $permission->label() }}</span></label>@endforeach</div>
                    <button class="btn btn--primary btn--sm" type="submit">Сохранить права</button>
                </form>
                <form class="mt-2" method="POST" action="{{ route('events.participants.responsibility.destroy', [$event->routeIdentifier(), $participant->id]) }}">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm" type="submit">Снять назначение</button></form>
            @endif
        </article>
    @endforeach
</section>
@endif

@if($allows(EventResponsibilityPermissionEnum::MANAGE_MINI_GAMES))
<section class="section-card mb-4">
    <h2>Игры и мини-игры</h2>
    <p class="form-hint">Создание и оперативное управление играми выполняется в отдельном рабочем интерфейсе.</p>
    @foreach($event->childEvents as $miniGame)<a class="btn btn--secondary btn--sm me-2 mb-2" href="{{ route('events.game.manage', $miniGame->routeIdentifier()) }}">{{ $miniGame->title }}</a>@endforeach
    <form method="POST" action="{{ route('events.games.store', $event->routeIdentifier()) }}" class="mt-3">@csrf
        <input type="hidden" name="title" value="Мини-игра">
        <input type="hidden" name="side_a_size" value="3"><input type="hidden" name="side_b_size" value="3">
        <button class="btn btn--primary btn--sm" type="submit">Добавить мини-игру 3×3</button>
    </form>
</section>
@endif

@if($canManageResult)
<section class="section-card mb-4">
    <h2>Итоги мероприятия</h2>
    <form method="POST" action="{{ route('events.result.update', $event->routeIdentifier()) }}">@csrf @method('PUT')
        <label class="form-label" for="event-management-result">Как прошло мероприятие</label>
        <textarea id="event-management-result" class="form-control" name="result_description" rows="5" maxlength="10000">{{ old('result_description', $event->result_description) }}</textarea>
        <button class="btn btn--primary btn--sm mt-2" type="submit">Сохранить итоги</button>
    </form>
</section>
@endif

@if($canCancel)
<section class="section-card mb-4">
    <h2>Отмена мероприятия</h2>
    <form method="POST" action="{{ route('events.cancel', $event->routeIdentifier()) }}" onsubmit="return confirm('Отменить мероприятие и освободить бронь?')">@csrf
        <label class="form-label" for="event-management-cancel-reason">Причина</label>
        <textarea id="event-management-cancel-reason" class="form-control" name="reason" rows="3" maxlength="1000"></textarea>
        <button class="btn btn--danger btn--sm mt-2" type="submit">Отменить мероприятие</button>
    </form>
</section>
@endif
@endsection
