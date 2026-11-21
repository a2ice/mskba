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
    $finished = $event->hasEnded();
    $feedbackManager = $event->canManageFeedback(auth()->user());
    $feedback = $finished && $eventWallCanView ? app(\App\Modules\Social\Application\Services\SocialWallService::class)->reportFeedback($event, auth()->user()) : ['likes'=>0,'can_react'=>false,'reaction'=>null];
    $canJoin = $editable && $eventWallCanView && (($event->status === EventStatusEnum::PUBLISHED && ($event->visibility->value === 'public' || $owner || $currentParticipant)) || ($owner && $event->status === EventStatusEnum::DRAFT && !$event->starts_at));
    $reportMedia = $finished ? $event->media->concat($event->media()->where('collection','event_report_media')->orderBy('sort_order')->get()) : collect();
    $rentalActive = $booking && $booking->status->value === 'confirmed' && $booking->ends_at?->isFuture();
    $rentalCreator = $booking?->creatorActor?->user?->canonical();
    $rentalCreatorName = $rentalCreator && app(\App\Modules\Identity\Application\Services\UserPrivacyAccessService::class)->allows($rentalCreator, auth()->user()?->canonical(), \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::PROFILE)
        ? ($rentalCreator->nickname ?: $rentalCreator->username ?: 'Организатор') : 'Организатор';
    $venueUrl = route('venues.show', $venue->routeIdentifier());
    $shareUrl = route('events.show', $event->routeIdentifier());
    $confirmedCount = app(\App\Modules\Event\Application\Services\EventIdentityParticipationService::class)->confirmedIdentityCount($event);
    $participants = $event->participants->sortBy(fn ($p) => $p->role === EventParticipantRoleEnum::ORGANIZER ? 0 : 1)->unique(fn ($p) => $p->user?->canonical()?->id ?? $p->user_id);
    $interest = $canManage ? $event->venueInterests->sortByDesc('revision')->first() : null;
    $policy = $canEdit && $event->starts_at && !$booking && $event->status === EventStatusEnum::PUBLISHED
        ? \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->where('is_enabled', true)->first() : null;
@endphp
@extends('theme::layouts.app', ['title' => $event->title])
@section('content')
<section class="container app-venue-page app-event-page venue-play-dialog--app" data-native-event-page>
    @if(session('status') || session('error') || session('photo_error') || $errors->any())
        <p class="notice" role="status">{{ session('error') ?: session('photo_error') ?: session('status') ?: $errors->first() }}</p>
    @endif
    <div class="app-venue-page__hero-grid">
        <div class="app-venue-gallery panel" data-venue-gallery>
            @if($photo)
                <div class="app-venue-gallery__stage"><img class="app-venue-gallery__slide is-active" src="{{ $photo->publicUrl() }}" alt="{{ $venue->name }}" data-gallery-slide></div>
            @else
                <div class="app-venue-gallery__missing"><svg aria-hidden="true"><use href="#ball"/></svg></div>
            @endif
        </div>
        <div class="app-venue-intro panel app-event-intro">
            <div class="app-venue-intro__badges">
                <span class="badge">{{ $event->type->label() }}</span><span class="badge">{{ $event->visibility->label() }}</span>
                @if($finished)
                <span class="badge app-event-completed">Завершено <span class="app-event-like-tally"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#heart"/></svg><span data-report-like-count role="status" aria-label="Лайков: {{ $feedback['likes'] }}">{{ $feedback['likes'] }}</span></span></span>
                @elseif($event->status !== EventStatusEnum::PUBLISHED)
                <span class="badge">{{ $event->status->label() }}</span>
                @endif
            </div>
            <div class="app-entity-heading"><h1>{{ $event->title }}</h1></div>
            @if($event->starts_at)
                <strong>{{ $event->starts_at->setTimezone($timezone)->locale('ru')->translatedFormat('j F Y · H:i') }}–{{ $event->ends_at?->setTimezone($timezone)->format('H:i') }}</strong>
                @if(!$finished && (!$booking || $booking->status->value !== 'confirmed'))<span class="muted">Время предварительное</span>@endif
                @if(count($event->proposed_time_options ?? []) > 1)
                    <ul>@foreach($event->proposed_time_options as $option)<li>{{ str_replace('T', ' ', $option['starts_at']) }} · {{ $option['duration_minutes'] }} мин.</li>@endforeach</ul>
                @endif
            @else
                <p>Дата и время не определены</p>
                @if($owner && $editable)<button type="button" class="button secondary" data-event-dialog-open="event-schedule">Указать дату и время</button>@endif
            @endif
            @if(filled($event->description))<p class="app-venue-intro__copy app-event-description">{{ $event->description }}</p>@endif
            @if($eventWallCanView)
            <div class="app-venue-interest-summary__profiles app-event-audience">
                <div class="app-venue-interest-summary__avatars" data-event-audience-avatars aria-hidden="true"></div>
                <a class="app-event-audience-count" href="#event-participants" data-event-audience-count>{{ $confirmedCount }} · Участники</a>
            </div>
            @endif
            <div class="app-venue-intro__actions">
                @if($canJoin)<button type="button" class="button primary large" data-event-participate aria-haspopup="dialog">Участвовать</button>@endif
                @if($event->visibility->value === 'public')<button type="button" class="button secondary large" data-event-dialog-open="event-share">Поделиться</button>@endif
            </div>
        </div>
    </div>
    <nav class="app-venue-page__anchors" aria-label="Разделы мероприятия" data-venue-section-nav>
        <a href="#event-where">Где играем</a><a href="#event-rental">Аренда</a>
        @if($eventWallCanView)<a href="#event-participants">Участники</a><a href="#event-discussion">Обсуждение</a>@endif
        <a href="#event-report">Как это было</a>
    </nav>
    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            <section class="panel app-venue-block app-event-where" id="event-where" data-venue-id="{{ $venue->id }}">
                <header class="app-venue-block__header"><h2>Где играем</h2></header>
                <a class="app-event-link" href="{{ $venueUrl }}" target="_blank" rel="noopener noreferrer">{{ $venue->name }}</a>
                <p>{{ $courtLabel }}</p>
                @if($address)<button type="button" class="app-venue-intro__address" data-venue-map-open aria-label="Посмотреть на карте"><svg aria-hidden="true"><use href="#pin"/></svg><span>{{ $address }}</span></button>@endif
                @if($venue->location?->metroStations?->isNotEmpty())
                    <div class="app-venue-intro__metro" aria-label="Ближайшие станции метро">
                        @foreach($venue->location->metroStations->loadMissing('line') as $station)
                            <span class="app-venue-intro__station">
                                <span class="app-venue-intro__metro-dot"
                                    style="--metro-color: {{ preg_match('/\A#?[0-9A-Fa-f]{6}\z/D', (string) $station->line?->color) ? '#'.ltrim((string) $station->line->color, '#') : '#758392' }}"
                                    aria-hidden="true"></span>{{ $station->name }}
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="app-event-actions">
                    <a class="button secondary" href="{{ $venueUrl }}" target="_blank" rel="noopener noreferrer">К площадке</a>
                </div>
                @unless($address)<button type="button" class="app-event-link" data-venue-map-open>Посмотреть на карте</button>@endunless
            </section>
            <section class="panel app-venue-block" id="event-rental">
                <header class="app-venue-block__header"><h2>Аренда</h2></header>
            @if($booking)
                <button type="button" class="app-venue-activity" data-event-dialog-open="event-booking" aria-haspopup="dialog"><span class="app-venue-activity__meta"><span>{{ $booking->starts_at?->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $booking->ends_at?->setTimezone($timezone)->format('H:i') }}</span><span class="badge">{{ $rentalActive ? 'Активна' : 'Неактивна' }}</span></span><strong class="app-venue-activity__title">{{ $courtLabel }}</strong><span class="app-venue-activity__description">{{ $venue->name }}</span></button>
            @else
                <p class="muted">Площадка пока не забронирована.</p>
                @if($canEdit && $event->starts_at && $event->status === EventStatusEnum::PUBLISHED)
                    @if(!app(\App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter::class)->hasVerifiedContact(auth()->user()))
                        @php
                            $pendingEmail = auth()->user()->canonical()->identityContactsQuery()
                                ->where('type', 'email')->whereNull('verified_at')->latest('id')->first();
                        @endphp
                        <div class="app-event-contact-required" role="status">
                            <p>Для бронирования необходимо иметь подтверждённый контакт</p>
                            <button type="button" class="button primary" data-event-contact-required
                                @if($pendingEmail) data-contact-id="{{ $pendingEmail->id }}" data-contact-value="{{ $pendingEmail->value }}" @endif>
                                {{ $pendingEmail ? 'Продолжить подтверждение' : 'Подтвердить контакт' }}
                            </button>
                        </div>
                    @elseif($court && ($policy || $venue->hasFreeAccess()))
                        <form method="POST" action="{{ $policy ? route('events.booking.quote', $event->routeIdentifier()) : route('events.booking.store', $event->routeIdentifier()) }}">
                            @csrf <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                            <button class="button primary" type="submit">{{ $policy ? 'Рассчитать и забронировать' : 'Забронировать бесплатную площадку' }}</button>
                        </form>
                    @elseif(!$court)
                        <p class="muted">Выберите зал для бронирования.</p>
                    @endif
                @endif
            @endif

            </section>
            @if($eventWallCanView)
            <div class="app-event-chat-layout venue-play-dialog--app" data-venue-play-page data-event-native-discussion
                data-venue-play-pane="{{ $event->id }}" data-authenticated="{{ auth()->check() ? '1' : '0' }}"
                data-event-status="{{ $currentParticipant?->confirmation_version === $event->participation_confirmation_version ? $currentParticipant?->status?->value : 'left' }}"
                data-event-organizer="{{ $owner ? '1' : '0' }}"
                data-event-page-url="{{ $shareUrl }}"
                data-event-participation-url="{{ route('events.participation', $event->routeIdentifier()) }}"
                data-event-wall-url="{{ route('events.wall.index', $event->routeIdentifier()) }}"
                data-event-post-url="{{ route('events.wall.store', $event->routeIdentifier()) }}">
                <section class="panel app-venue-block" id="event-participants">
                    @include('components.event-discussion', ['chatEvent'=>$event,'chatCanRespond'=>false,'chatShowRoster'=>true,'chatShowWall'=>false,'primaryButton'=>'button primary','secondaryButton'=>'button secondary'])
                </section>
                <section class="panel app-venue-block" id="event-discussion">
                    <header class="app-venue-block__header"><h2>Обсуждение</h2></header>
                    @include('components.event-discussion', ['chatEvent'=>$event,'chatCanRespond'=>false,'chatShowRoster'=>false,'primaryButton'=>'button primary','secondaryButton'=>'button secondary'])
                </section>
            </div>
            @endif
            @include('theme::pages.events.partials.event-report')
        </div>
        <aside class="app-venue-page__aside panel app-event-aside">
            @if($canEdit || $canInvite || $interest || $feedbackManager || $owner)
                @if($canEdit)<a class="button secondary" href="{{ route('events.edit', $event->routeIdentifier()) }}">Редактировать мероприятие</a>@endif
                @if($feedbackManager)<button class="button secondary" data-event-dialog-open="event-feedback-settings">Обсуждение и комментарии</button>@endif
                @if($canInvite)<button type="button" class="button secondary" data-event-dialog-open="event-invite">Пригласить участников</button>@endif
                @if($interest)<a class="button secondary" href="{{ route('events.interests.show', [$event->routeIdentifier(), $interest->id]) }}">Переписка с площадкой</a>@endif
                @if($owner)<button type="button" class="button secondary event-lifecycle-danger" data-event-lifecycle-open>Удалить мероприятие</button>@endif
            @endif
            <strong>Игры</strong>
            @forelse($event->games as $child)
                @if($event->starts_at)
                    <a class="app-venue-activity" href="{{ $event->primary_game_id === $child->id && $event->type->value === 'game' ? route('events.show', ['event' => $event->routeIdentifier(), 'sport' => 1]) : route('events.games.show', [$event->routeIdentifier(), $child->id]) }}">
                        <strong class="app-venue-activity__title">{{ $child->title ?: 'Игра' }}</strong><span class="app-venue-activity__description">{{ $child->formatLabel() }} · {{ $child->status?->label() }}</span>
                    </a>
                @else <p>{{ $child->title ?: 'Игра' }} · {{ $child->formatLabel() }}</p> @endif
                @if($event->starts_at && $effectivePermissions->contains(Permission::UPDATE_MINI_GAME->value))<a class="button secondary" href="{{ route('events.games.manage', [$event->routeIdentifier(), $child->id]) }}">Управлять игрой</a>@endif
            @empty <p>Игр пока нет</p> @endforelse
        </aside>
    </div>
    @if($event->venueRevisions->isNotEmpty())
        <article class="panel app-event-panel"><h2>История изменений места и времени</h2>
            @foreach($event->venueRevisions->sortByDesc('revision') as $revision)
                <p>{{ $revision->created_at->format('d.m.Y H:i') }}: {{ $revision->previousVenue?->name }} → {{ $revision->nextVenue?->name }}. {{ $revision->reason }}</p>
            @endforeach
        </article>
    @endif

</section>
@include('theme::pages.events.partials.event-detail-dialogs')
@if($eventWallCanView)
    @include('components.event-discussion-delete-dialog', ['isAppTheme' => true])
    @include('components.event-discussion-styles')
@endif
@if($owner)
    @include('theme::pages.events.partials.lifecycle-dialog', ['restoring' => false])
@endif
@include('theme::partials.venue-map-dialog', ['mapVenue' => null, 'mapShowCurrentVenueLink'=>true])
@if($booking)
<dialog class="mskba-modal app-event-dialog" id="event-booking" aria-labelledby="event-booking-title">
    <header class="mskba-modal__header"><h2 id="event-booking-title">Бронирование №{{ $booking->id }}</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body mskba-scroll app-event-panel"><span class="badge">{{ $rentalActive ? 'Активна' : 'Неактивна' }}</span><p>{{ $venue->name }} · {{ $courtLabel }}</p><p>{{ $address }}</p><p>Организатор аренды: {{ $rentalCreatorName }}</p>
        <p>{{ $booking->starts_at?->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $booking->ends_at?->setTimezone($timezone)->format('d.m.Y H:i') }}</p>
        @if($booking->scope)<p>{{ $booking->scope->label() }}</p>@endif
    </div>
    @if($owner && $event->sourceBooking)<footer class="mskba-modal__footer"><a class="button secondary" href="{{ route('account.venue-bookings.show', $event->sourceBooking) }}">Перейти к заявке</a></footer>@endif
</dialog>
@endif
@if(!$event->starts_at && $owner && $editable)
<dialog class="mskba-modal app-event-dialog" id="event-schedule" aria-labelledby="event-schedule-title">
    <header class="mskba-modal__header"><h2 id="event-schedule-title">Указать желаемую дату</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <form method="POST" action="{{ route('events.funnel.schedule', $event->routeIdentifier()) }}">@csrf
        <div class="mskba-modal__body mskba-scroll app-event-panel">
            <label class="field-group">Дата и время (МСК)<input name="starts_at" type="datetime-local" min="{{ now($timezone)->format('Y-m-d\TH:i') }}" required></label>
            <label class="field-group">Продолжительность<span class="select-control"><select name="duration_minutes">@foreach(range(30,480,30) as $duration)<option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>@endforeach</select><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></label>
        </div><footer class="mskba-modal__footer"><button type="submit" class="button primary">Опубликовать и отправить предзаявку</button></footer>
    </form>
</dialog>
@endif
@if($canInvite)
<dialog class="mskba-modal app-event-dialog" id="event-invite" aria-labelledby="event-invite-title" data-event-invite data-candidates-url="{{ route('events.participants.candidates', $event->routeIdentifier()) }}">
    <header class="mskba-modal__header"><h2 id="event-invite-title">Пригласить участников</h2><button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <form method="POST" action="{{ route('events.participants.manage.store', $event->routeIdentifier()) }}">@csrf
        <div class="mskba-modal__body mskba-scroll app-event-panel"><label class="field-group">Найти пользователя<input type="search" data-event-invite-query placeholder="Имя или логин" autocomplete="off"></label>
            <label class="field-group">Пользователь<span class="select-control"><select name="user_id" data-event-invite-results required></select><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></label>
            <p data-event-invite-feedback role="status"></p>
        </div><footer class="mskba-modal__footer"><button type="submit" class="button primary">Пригласить</button></footer>
    </form>
</dialog>
@endif
@endsection
@push('styles')
<style>
.app-event-page{padding-block:24px 48px;min-width:0}
.app-event-intro{display:flex;flex-direction:column;gap:16px}.app-event-intro .app-venue-intro__actions{margin-top:auto}
.app-event-chat-layout{display:contents}.app-event-chat-layout>.app-venue-block{scroll-margin-top:calc(var(--app-sticky-offset,124px) + var(--app-venue-nav-height,58px) + 15px)}
.app-event-page .app-venue-block{min-width:0;align-items:stretch}.app-event-page .venue-play-dialog__wall{border:0;padding:0}.app-event-page .venue-play-dialog__messages{max-height:420px}
.app-event-page .app-venue-block p{margin:0}.app-event-where .app-venue-intro__address{align-self:flex-start}
.app-event-audience{display:flex;align-items:center;gap:12px}.app-event-audience .app-venue-interest-summary__avatar{position:relative;overflow:visible;border-color:var(--surface)}
.app-event-audience .app-venue-interest-summary__avatar img{border-radius:50%}.app-event-audience .app-venue-interest-summary__avatar svg{width:24px;height:24px}
.app-event-audience .app-venue-interest-summary__avatar::after{content:"";position:absolute;right:0;bottom:0;width:10px;height:10px;border:2px solid var(--surface);border-radius:50%;background:var(--success)}
.app-event-audience .app-venue-interest-summary__avatar[data-status="tentative"]::after{background:var(--warning)}.app-event-audience .app-venue-interest-summary__avatar[data-status="left"]::after{background:var(--danger)}
.app-event-audience-count{color:var(--accent);font-weight:650}.app-event-completed,.app-event-like-tally{display:inline-flex;align-items:center;gap:6px}.app-event-like-tally svg{display:block;width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.app-event-aside .app-venue-activity__title{font-size:14px}.app-event-aside{min-width:0}
.app-event-report-chat{display:grid;gap:12px;min-width:0}.app-event-report-chat .venue-play-dialog__wall{border-top:1px solid var(--line);padding-top:16px}
.app-event-report-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px}
.app-event-report-grid button{padding:0;overflow:hidden;aspect-ratio:4/3;border-radius:12px;border:1px solid var(--line);background:var(--surface-raised);position:relative;cursor:pointer}
.app-event-report-grid :is(img,video){width:100%;height:100%;object-fit:cover;display:block}.app-event-report-grid .app-event-video-play{position:absolute;inset:50% auto auto 50%;transform:translate(-50%,-50%);color:#fff;background:#18212bcc;padding:12px;border-radius:50%}
.app-event-report-reactions button{display:inline-flex;gap:7px;align-items:center}.app-event-report-reactions svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.app-event-report-reactions button[aria-pressed="true"]{color:var(--accent);border-color:var(--accent);background:var(--surface-raised)}
.app-event-media-viewer{width:min(800px,calc(100vw - 24px))}.app-event-media-viewer :is(img,video){display:block;max-width:100%;max-height:65dvh;margin:auto}
.app-event-dialog .app-event-choice-row{display:flex;justify-content:space-around;gap:12px;flex-wrap:wrap}.app-event-dialog .app-event-choice-row .button{min-width:125px;margin:0}
.app-event-dialog>.mskba-modal__header{display:grid;grid-template-columns:16px minmax(0,1fr) 44px;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid var(--line)}
.app-event-dialog>.mskba-modal__header h2{margin:0;font-size:clamp(19px,2vw,25px);grid-column:2}.app-event-dialog>.mskba-modal__header .icon-button{grid-column:3;width:44px;height:44px}
.app-event-dialog .mskba-modal__body{padding:20px 22px}.app-event-dialog .mskba-modal__footer{padding:16px 22px;border-top:1px solid var(--line)}.app-event-page [hidden],.app-event-dialog [hidden]{display:none!important}
.event-feedback-fields{display:grid;gap:12px}.event-feedback-fields label{display:flex;align-items:flex-start;gap:10px}
@media(max-width:900px){.app-event-page .app-event-aside{display:flex;position:static}.app-event-page .app-event-report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.app-event-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:20px;align-items:start}
.app-event-column,.app-event-panel{display:grid;gap:16px;min-width:0;align-content:start}
.app-event-panel{padding:24px}
.app-event-panel :is(h2,h3,p),.app-event-page h1{margin:0;overflow-wrap:anywhere}
.app-event-panel h2,.app-event-page .event-social-wall__header h2{font-size:20px;line-height:26px;font-weight:700;letter-spacing:-.015em}.app-event-panel h3{font-size:16px;line-height:24px;font-weight:700}
.app-event-photo{width:100%;max-height:300px;object-fit:cover;border-radius:12px}
.app-event-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.app-event-link{color:var(--accent);text-decoration:underline;text-underline-offset:3px;background:none;border:0;padding:0;cursor:pointer;text-align:left;justify-self:start;min-height:44px;display:inline-flex;align-items:center}
.app-event-booking{display:grid;grid-template-columns:minmax(0,1fr) 20px;gap:8px;padding:16px;min-height:44px;border:1px solid var(--control-line);border-radius:12px;background:var(--surface);color:inherit;text-align:left;cursor:pointer}.app-event-booking:hover{background:var(--surface-raised);border-color:var(--muted)}.app-event-booking strong{grid-column:1;font-size:14px}.app-event-booking__arrow{grid-column:2;grid-row:1/3;align-self:center;width:20px;height:20px;color:var(--muted)}
.app-event-person{display:flex;gap:12px;align-items:center}
.app-event-person img,.app-event-initials{width:44px;height:44px;flex:none;border-radius:50%;object-fit:cover}
.app-event-initials{display:grid;place-items:center;border:1px solid var(--control-line)}
.app-event-person p{font-size:.9rem;margin-top:4px}.app-event-person .badge{margin-inline-start:6px}
.app-event-dot{color:var(--warning)}
.app-event-dot[data-response="confirmed"]{color:var(--success)}.app-event-dot[data-response="left"]{color:var(--danger)}
.app-event-contact-required{display:grid;gap:12px;border:1px solid color-mix(in srgb,var(--danger) 45%,var(--line));border-radius:12px;padding:16px;margin-bottom:8px}.app-event-contact-required .button{justify-self:start}
.app-event-description{white-space:pre-wrap}
html:has(.app-event-dialog[open]){overflow:hidden}
.app-event-dialog>form{display:flex;flex-direction:column;flex:1 1 auto;min-width:0;min-height:0;overflow:hidden}
.app-event-dialog .mskba-modal__body{display:block}.app-event-dialog .mskba-modal__body>*+*{margin-top:16px}
.app-event-dialog .field-group{display:grid;gap:8px}.app-event-dialog .mskba-modal__footer{display:flex;justify-content:flex-end;gap:12px}
.app-event-page .event-social-wall{display:grid;gap:16px;padding:24px}
.app-event-page .event-social-wall__header h2,.app-event-page .event-social-wall__entry p{margin:0}
.app-event-page .event-social-wall__stats,.app-event-page .event-social-wall__tabs,.app-event-page .event-social-wall__form-actions,.app-event-page .event-social-wall__entry-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.app-event-page .event-social-wall__list{display:grid;gap:12px;list-style:none;padding:0;max-height:420px;overflow:auto}
.app-event-page .event-social-wall__entry{display:grid;gap:6px;padding:12px;border:1px solid var(--line);border-radius:12px;overflow-wrap:anywhere}
.app-event-page .event-social-wall__entry.is-system,.app-event-page .event-social-wall__muted{font-size:14px;line-height:20px;color:var(--muted)}
.app-event-page .event-social-wall__tabs{justify-content:flex-start}.app-event-page .event-social-wall__tab.is-active{color:var(--accent);border-color:var(--accent)}
.app-event-page .event-social-wall__form{display:grid;gap:8px}.app-event-page .event-social-wall__form textarea{width:100%}
.app-event-page .event-social-wall__delete{color:var(--accent);background:none;border:0;text-align:left;cursor:pointer;min-height:44px;justify-self:start}
.app-event-page .is-struck{text-decoration:line-through}
@media(max-width:767px){.app-event-grid{grid-template-columns:1fr;gap:16px}.app-event-page{gap:16px}.app-event-panel,.app-event-page .event-social-wall{padding:20px}.app-event-actions form{flex:1}.app-event-actions form button{width:100%}}
</style>
@endpush
@push('scripts')
<script type="module">
const requiredContact=document.querySelector('[data-event-contact-required]');
if(requiredContact){
    let changed=false;
    const contactModal=document.querySelector('[data-contact-add-modal]');
    requiredContact.addEventListener('click',()=>{
        if(!contactModal){location.reload();return;}
        window.dispatchEvent(new CustomEvent('mskba:contact:add',{detail:{
            resume:requiredContact.dataset.contactId ? {id:Number(requiredContact.dataset.contactId),value:requiredContact.dataset.contactValue} : null
        }}));
    });
    window.addEventListener('mskba:contact:pending',event=>{changed=true;if(event.detail?.id){requiredContact.dataset.contactId=event.detail.id;requiredContact.dataset.contactValue=event.detail.value;}requiredContact.textContent='Продолжить подтверждение';});
    window.addEventListener('mskba:contact:updated',()=>{changed=true;});
    contactModal?.addEventListener('close',()=>{if(changed)location.reload();});
}
document.querySelectorAll('[data-event-dialog-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.eventDialogOpen)?.showModal()));
document.querySelectorAll('[data-event-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
document.querySelectorAll('.app-event-dialog').forEach(dialog=>{let outside=false;const onBackdrop=e=>{const rect=dialog.getBoundingClientRect();return e.target===dialog&&(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom);};dialog.addEventListener('pointerdown',e=>outside=onBackdrop(e));dialog.addEventListener('pointerup',e=>{if(outside&&onBackdrop(e))dialog.close();outside=false;});dialog.addEventListener('pointercancel',()=>outside=false);});
document.querySelectorAll('[data-event-dialog-next]').forEach(b=>b.addEventListener('click',()=>{b.closest('dialog')?.close();document.getElementById(b.dataset.eventDialogNext)?.showModal();}));
document.querySelector('[data-event-copy]')?.addEventListener('click',async e=>{try{await navigator.clipboard.writeText(e.currentTarget.dataset.eventCopy);e.currentTarget.textContent='Ссылка скопирована';}catch{e.currentTarget.textContent='Не удалось скопировать';}});
document.querySelector('#event-report-editor form')?.addEventListener('submit',event=>{
    const form=event.currentTarget,files=[...form.querySelectorAll('input[type="file"]')].flatMap(input=>[...input.files]);
    const warning=form.querySelector('[data-report-upload-error]');
    const invalid=files.some(f=>f.size>10*1024*1024)||files.reduce((n,f)=>n+f.size,0)>18*1024*1024;
    warning.hidden=!invalid;warning.textContent=invalid?'До 10 МБ на файл, до 18 МБ за одну загрузку.':'';
    if(invalid)event.preventDefault();
});
document.querySelectorAll('[data-event-media-open]').forEach(b=>b.addEventListener('click',()=>{
    const viewer=document.getElementById('event-media-viewer'),body=viewer.querySelector('[data-event-media-body]');
    const media=document.createElement(b.dataset.mediaKind==='video'?'video':'img');media.src=b.dataset.eventMediaOpen;
    if(media.tagName==='VIDEO'){media.controls=true;media.playsInline=true;}else media.alt='Фото мероприятия';
    body.replaceChildren(media);viewer.showModal();
}));
document.getElementById('event-media-viewer')?.addEventListener('close',e=>e.currentTarget.querySelector('[data-event-media-body]').replaceChildren());
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
