@php
    use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
    use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum as Permission;
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    $timezone = $event->venue->schedule?->timezone ?: 'Europe/Moscow';
    $venue = $event->venue;
    $address = $venue->location?->address?->full_address ?: $venue->raw_address;
    $booking = $event->sourceBooking ?? $event->booking;
    $court = $booking?->court ?? $event->court;
    $courtLabel = $court?->name ?? 'Любой зал';
    $photo = $court?->media?->first(fn ($item) => $item->collection === 'gallery') ?? $venue->media->first();
    $owner = auth()->user() && $event->organizerActor?->user?->canonical()?->id === auth()->user()->canonical()->id;
    // The public shell must not expose powers granted only by global admin status.
    $scopedPermissions = auth()->user() ? collect(app(\App\Modules\Contract\Application\Services\EntityContractManager::class)
        ->effectivePermissions(auth()->user(), \App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum::EVENT, $event->id)) : collect();
    $effectivePermissions = $effectivePermissions->intersect($scopedPermissions);
    $canManage = $effectivePermissions->isNotEmpty();
    $editable = !in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true) && (!$event->ends_at || $event->ends_at->isFuture());
    $canEdit = $editable && $effectivePermissions->contains(Permission::UPDATE_EVENT->value);
    $canInvite = $editable && $event->status === EventStatusEnum::PUBLISHED && $effectivePermissions->contains(Permission::MANAGE_PARTICIPANTS->value);
    $canRespond = auth()->check() && $editable && (($owner && $event->status === EventStatusEnum::DRAFT && !$event->starts_at) || ($event->status === EventStatusEnum::PUBLISHED && ($owner || $currentParticipant || $event->visibility->value === 'public')));
    $confirmedCount = app(\App\Modules\Event\Application\Services\EventIdentityParticipationService::class)->confirmedIdentityCount($event);
    $participants = $event->participants->sortBy(fn ($p) => $p->role === EventParticipantRoleEnum::ORGANIZER ? 0 : 1)->unique(fn ($p) => $p->user?->canonical()?->id ?? $p->user_id);
    $interest = $canManage ? $event->venueInterests->sortByDesc('revision')->first() : null;
    $policy = $canEdit && $event->starts_at && !$booking && $event->status === EventStatusEnum::PUBLISHED
        ? \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->where('is_enabled', true)->first() : null;
@endphp
@extends('theme::layouts.app', ['title' => $event->title])
@section('content')
<section class="container app-event-page" data-native-event-page>
    <header class="app-account-overview__intro">
        <a href="{{ route('events.index') }}" class="app-event-link">Все мероприятия</a>
        <div class="app-entity-heading"><h1>{{ $event->title }}</h1></div>
        <div class="app-event-actions"><span class="badge">{{ $event->type->label() }}</span><span class="badge">{{ $event->status->label() }}</span><span class="badge">{{ $event->visibility->label() }}</span>
            @if($event->status === EventStatusEnum::PUBLISHED && $event->visibility->value === 'public')
                <button type="button" class="button secondary" data-event-share>Поделиться</button>
                <button type="button" class="button secondary" data-favorite-type="event" data-favorite-id="{{ $event->id }}" data-favorite-name="{{ $event->title }}" aria-label="Избранное">Избранное</button>
            @endif
        </div>
    </header>
    @if(session('status') || session('error') || $errors->any())
        <p class="notice" role="status">{{ session('error') ?: session('status') ?: $errors->first() }}</p>
    @endif
    @if(!$event->starts_at)<p class="notice">Мероприятие сохранено как черновик. Дата пока не указана, мероприятие не опубликовано.</p>@endif
    <div class="app-event-grid">
        <article class="panel app-event-panel" data-venue-id="{{ $venue->id }}">
            @if($photo)<img class="app-event-photo" src="{{ $photo->publicUrl() }}" alt="{{ $venue->name }}">@endif
            <h2>Площадка</h2>
            <a class="app-event-link" href="{{ route('venues.show', $venue->routeIdentifier()) }}">{{ $venue->name }}</a>
            <p>Зал: <strong>{{ $courtLabel }}</strong></p>
            @if($address)<p>{{ $address }}</p>@endif
            @if($venue->location?->metroStations?->isNotEmpty())
                <div class="app-event-actions">@foreach($venue->location->metroStations as $station)<span class="badge">{{ $station->name }}</span>@endforeach</div>
            @endif
            <button type="button" class="app-event-link" data-venue-map-open>Посмотреть на карте</button>
            <a class="app-event-link" href="{{ route('venues.show', ['alias' => $venue->routeIdentifier(), 'availability' => 1, 'availability_court' => $court?->id, 'availability_date' => $event->starts_at?->setTimezone($timezone)->format('Y-m-d'), 'availability_time' => $event->starts_at?->setTimezone($timezone)->format('H:i')]) }}">Посмотреть занятость площадки</a>
            <h2>Дата и время</h2>
            @if($event->starts_at)
                <p><strong>{{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $event->ends_at?->setTimezone($timezone)->format('H:i') }}</strong> (МСК)</p>
                @if(!$booking || $booking->status->value !== 'confirmed')<p class="muted">Желаемое время, бронирование не подтверждено.</p>@endif
                @if(count($event->proposed_time_options ?? []) > 1)
                    <h3>Желаемые интервалы</h3>
                    <ul>@foreach($event->proposed_time_options as $option)<li>{{ str_replace('T', ' ', $option['starts_at']) }} · {{ $option['duration_minutes'] }} мин.</li>@endforeach</ul>
                @endif
            @else
                <p>Дата и время не определены</p>
                @if($owner && $editable)<button type="button" class="button primary" data-event-dialog-open="event-schedule">Указать дату и время</button>@endif
            @endif
            <h2>Бронирование</h2>
            @if($booking)
                <button type="button" class="app-event-booking" data-event-dialog-open="event-booking" aria-haspopup="dialog"><span>Бронирование №{{ $booking->id }}</span><strong>{{ $booking->status->label() }}</strong><span class="app-event-link">Подробнее</span></button>
            @else
                <p>Площадка пока не забронирована.</p>
                @if($interest)<a class="app-event-link" href="{{ route('events.interests.show', [$event->routeIdentifier(), $interest->id]) }}">Переписка с площадкой по предзаявке</a>@endif
                @if($canEdit && $event->starts_at && $event->status === EventStatusEnum::PUBLISHED)
                    @if($court && ($policy || $venue->hasFreeAccess()))
                        <form method="POST" action="{{ $policy ? route('events.booking.quote', $event->routeIdentifier()) : route('events.booking.store', $event->routeIdentifier()) }}">
                            @csrf <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                            <button class="button primary" type="submit">{{ $policy ? 'Рассчитать и забронировать' : 'Забронировать бесплатную площадку' }}</button>
                        </form>
                    @elseif(!$court)
                        <p class="muted">Сначала согласуйте конкретный зал с площадкой.</p>
                    @endif
                @endif
            @endif
        </article>
        <div class="app-event-column">
            <article class="panel app-event-panel">
                <h2>Участники <span class="badge">{{ $confirmedCount }}{{ $event->max_participants ? '/'.$event->max_participants : '' }}</span></h2>
                @if($canRespond)
                    <div class="app-event-actions" aria-label="Мой ответ на участие">
                        @foreach(['confirmed'=>'Пойду','tentative'=>'Думаю','left'=>'Не пойду'] as $status=>$label)
                            <form method="POST" action="{{ route('events.participation', $event->routeIdentifier()) }}">
                                @csrf @method('PATCH') <input type="hidden" name="status" value="{{ $status }}">
                                <button type="submit" class="button {{ $currentParticipant?->status?->value === $status ? 'primary' : 'secondary' }}">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                @elseif(auth()->guest() && $editable)
                    <p class="muted">Войдите, чтобы ответить на приглашение или присоединиться.</p>
                @endif
                @foreach($participants as $participant)
                    @php
                        $user = $participant->user;
                        $name = trim(($user?->profile?->first_name ?? '').' '.($user?->profile?->last_name ?? '')) ?: ($user?->username ?: 'Пользователь');
                        $role = $participant->role === EventParticipantRoleEnum::ORGANIZER;
                        $response = $participant->status->value;
                        $stale = $response === 'confirmed' && $participant->confirmation_version !== $event->participation_confirmation_version;
                    @endphp
                    <div class="app-event-person">
                        @if($user?->profile?->avatarUrl())<img src="{{ $user->profile->avatarUrl() }}" alt="">@else<span class="app-event-initials">{{ mb_substr($name,0,1) }}</span>@endif
                        <div><strong>{{ $name }}</strong>@if($role)<span class="badge">Организатор</span>@endif
                            <p><span class="app-event-dot" data-response="{{ $stale ? 'tentative' : $response }}"></span> {{ $stale ? 'Нужно подтвердить повторно' : (['confirmed'=>'Пойдёт','tentative'=>'Думает','left'=>'Не пойдёт'][$response] ?? $participant->status->label()) }}</p></div>
                    </div>
                @endforeach
                @if($participants->isEmpty())<p class="muted">Участников пока нет.</p>@endif
            </article>
            @if($canEdit || $canInvite || $interest)
                <article class="panel app-event-panel">
                    <h2>Управление мероприятием</h2>
                    @if($canEdit)<a class="button secondary" href="{{ route('events.edit', $event->routeIdentifier()) }}">Редактировать мероприятие</a>@endif
                    @if($canInvite)<button type="button" class="button primary" data-event-dialog-open="event-invite">Пригласить участников</button>@endif
                    @if($interest)<a class="button secondary" href="{{ route('events.interests.show', [$event->routeIdentifier(), $interest->id]) }}">Переписка с площадкой</a>@endif
                </article>
            @endif
            <article class="panel app-event-panel"><h2>Описание</h2><p class="app-event-description">{{ $event->description ?: 'Описание пока не добавлено.' }}</p></article>
            @if($event->games->isNotEmpty())
                <article class="panel app-event-panel"><h2>Игры внутри мероприятия</h2>
                    @foreach($event->games as $child)
                        <p>{{ $child->format?->label() ?? 'Игра' }} · {{ $child->status?->label() ?? '' }}</p>
                        @if($event->starts_at)
                            <a class="app-event-link" href="{{ $event->primary_game_id === $child->id && $event->type->value === 'game' ? route('events.show', ['event' => $event->routeIdentifier(), 'sport' => 1]) : route('events.games.show', [$event->routeIdentifier(), $child->id]) }}">Открыть игру</a>
                        @endif
                        @if($event->starts_at && $effectivePermissions->contains(Permission::UPDATE_MINI_GAME->value))<a class="button secondary" href="{{ route('events.games.manage', [$event->routeIdentifier(), $child->id]) }}">Управлять игрой</a>@endif
                    @endforeach
                </article>
            @endif
        </div>
    </div>
    @include('theme::pages.events.partials.social-wall')
    @if($event->venueRevisions->isNotEmpty())
        <article class="panel app-event-panel"><h2>История изменений места и времени</h2>
            @foreach($event->venueRevisions->sortByDesc('revision') as $revision)
                <p>{{ $revision->created_at->format('d.m.Y H:i') }}: {{ $revision->previousVenue?->name }} → {{ $revision->nextVenue?->name }}. {{ $revision->reason }}</p>
            @endforeach
        </article>
    @endif
</section>
@include('theme::partials.venue-map-dialog', ['mapVenue' => null])
@if($booking)
<dialog class="mskba-modal app-event-dialog" id="event-booking" aria-labelledby="event-booking-title">
    <header class="mskba-modal__header"><h2 id="event-booking-title">Бронирование №{{ $booking->id }}</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body app-event-panel"><span class="badge">{{ $booking->status->label() }}</span><p>{{ $venue->name }} · {{ $courtLabel }}</p><p>{{ $address }}</p>
        <p>{{ $booking->starts_at?->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $booking->ends_at?->setTimezone($timezone)->format('d.m.Y H:i') }}</p>
        @if($booking->scope)<p>{{ $booking->scope->label() }}</p>@endif
        @if($owner && $event->sourceBooking)<a class="button secondary" href="{{ route('account.venue-bookings.show', $event->sourceBooking) }}">Перейти к заявке</a>@endif
    </div>
</dialog>
@endif
@if(!$event->starts_at && $owner && $editable)
<dialog class="mskba-modal app-event-dialog" id="event-schedule" aria-labelledby="event-schedule-title">
    <header class="mskba-modal__header"><h2 id="event-schedule-title">Указать желаемую дату</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <form method="POST" action="{{ route('events.funnel.schedule', $event->routeIdentifier()) }}">@csrf
        <div class="mskba-modal__body app-event-panel"><p>Отправим предварительное пожелание, не бронь.</p>
            <label class="field-group">Дата и время (МСК)<input name="starts_at" type="datetime-local" min="{{ now($timezone)->format('Y-m-d\TH:i') }}" required></label>
            <label class="field-group">Продолжительность<span class="select-control"><select name="duration_minutes">@foreach(range(30,480,30) as $duration)<option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>@endforeach</select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
        </div><footer class="mskba-modal__footer"><button type="submit" class="button primary">Опубликовать и отправить предзаявку</button></footer>
    </form>
</dialog>
@endif
@if($canInvite)
<dialog class="mskba-modal app-event-dialog" id="event-invite" aria-labelledby="event-invite-title" data-event-invite data-candidates-url="{{ route('events.participants.candidates', $event->routeIdentifier()) }}">
    <header class="mskba-modal__header"><h2 id="event-invite-title">Пригласить участников</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <form method="POST" action="{{ route('events.participants.manage.store', $event->routeIdentifier()) }}">@csrf
        <div class="mskba-modal__body app-event-panel"><label class="field-group">Найти пользователя<input type="search" data-event-invite-query placeholder="Имя или логин" autocomplete="off"></label>
            <label class="field-group">Пользователь<span class="select-control"><select name="user_id" data-event-invite-results required></select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
            <p data-event-invite-feedback role="status"></p>
        </div><footer class="mskba-modal__footer"><button type="submit" class="button primary">Пригласить</button></footer>
    </form>
</dialog>
@endif
@endsection
@push('styles')
<style>
.app-event-page{display:grid;gap:24px;padding-block:24px 48px;min-width:0}
.app-event-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:20px;align-items:start}
.app-event-column,.app-event-panel{display:grid;gap:16px;min-width:0;align-content:start}
.app-event-panel{padding:clamp(16px,2vw,24px)}
.app-event-panel :is(h2,h3,p),.app-event-page h1{margin:0;overflow-wrap:anywhere}
.app-event-panel h2{font-size:1.25rem}.app-event-panel h3{font-size:1rem}
.app-event-photo{width:100%;max-height:300px;object-fit:cover;border-radius:12px}
.app-event-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.app-event-link{color:var(--accent);text-decoration:underline;text-underline-offset:3px;background:none;border:0;padding:0;cursor:pointer;text-align:left;justify-self:start}
.app-event-booking{display:grid;gap:8px;padding:16px;border:1px solid var(--control-line);border-radius:12px;background:transparent;color:inherit;text-align:left;cursor:pointer}
.app-event-person{display:flex;gap:12px;align-items:center}
.app-event-person img,.app-event-initials{width:44px;height:44px;flex:none;border-radius:50%;object-fit:cover}
.app-event-initials{display:grid;place-items:center;border:1px solid var(--control-line)}
.app-event-person p{font-size:.9rem;margin-top:4px}.app-event-person .badge{margin-inline-start:6px}
.app-event-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#b77916}
.app-event-dot[data-response="confirmed"]{background:#2a9955}.app-event-dot[data-response="left"]{background:#c44e4e}
.app-event-description{white-space:pre-wrap}.app-event-dialog{width:min(560px,calc(100vw - 24px))}
.app-event-dialog form{min-width:0}.app-event-dialog .field-group{display:grid;gap:8px}
.app-event-page .event-social-wall{display:grid;gap:16px;padding:clamp(16px,2vw,24px)}
.app-event-page .event-social-wall__header h2,.app-event-page .event-social-wall__entry p{margin:0}
.app-event-page .event-social-wall__stats,.app-event-page .event-social-wall__tabs,.app-event-page .event-social-wall__form-actions,.app-event-page .event-social-wall__entry-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.app-event-page .event-social-wall__list{display:grid;gap:12px;list-style:none;padding:0;max-height:420px;overflow:auto}
.app-event-page .event-social-wall__entry{display:grid;gap:6px;padding:12px;border:1px solid var(--control-line);border-radius:10px;overflow-wrap:anywhere}
.app-event-page .event-social-wall__entry.is-system,.app-event-page .event-social-wall__muted{font-size:.85rem;opacity:.85}
.app-event-page .event-social-wall__tab.is-active{color:var(--accent);border-color:var(--accent)}
.app-event-page .event-social-wall__form{display:grid;gap:8px}.app-event-page .event-social-wall__form textarea{width:100%}
.app-event-page .event-social-wall__delete{color:var(--accent);background:none;border:0;text-align:left;cursor:pointer}
.app-event-page .is-struck{text-decoration:line-through}
@media(max-width:760px){.app-event-grid{grid-template-columns:1fr}.app-event-page{gap:16px}.app-event-actions form{flex:1}.app-event-actions form button{width:100%}}
</style>
@endpush
@push('scripts')
<script type="module">
document.querySelectorAll('[data-event-dialog-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.eventDialogOpen)?.showModal()));
document.querySelectorAll('[data-event-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
document.querySelectorAll('.app-event-dialog').forEach(dialog=>{let outside=false;dialog.addEventListener('pointerdown',e=>outside=e.target===dialog);dialog.addEventListener('pointerup',e=>{if(outside&&e.target===dialog)dialog.close();outside=false;});});
document.querySelector('[data-native-event-page] [data-event-share]')?.addEventListener('click',async e=>{try{if(navigator.share)await navigator.share({title:document.title,url:location.href});else{await navigator.clipboard.writeText(location.href);e.target.textContent='Ссылка скопирована';}}catch{}});
const invite=document.querySelector('[data-event-invite]');
if(invite){
    const query=invite.querySelector('[data-event-invite-query]'),results=invite.querySelector('[data-event-invite-results]'),feedback=invite.querySelector('[data-event-invite-feedback]');
    let timer,request;
    query.addEventListener('input',()=>{clearTimeout(timer);request?.abort();results.replaceChildren();feedback.textContent='';const q=query.value.trim();if(q.length<2)return;
        timer=setTimeout(async()=>{request=new AbortController();try{const url=new URL(invite.dataset.candidatesUrl,location.href);url.searchParams.set('query',q);const response=await fetch(url,{headers:{Accept:'application/json'},signal:request.signal});if(!response.ok)throw Error('Не удалось найти пользователей');const data=await response.json();for(const user of data.users||[]){const option=document.createElement('option');option.value=String(user.id);option.textContent=user.name;results.append(option);}feedback.textContent=results.options.length?'':'Ничего не найдено';}catch(error){if(error.name!=='AbortError')feedback.textContent=error.message;}},250);
    });
}
</script>
@endpush
