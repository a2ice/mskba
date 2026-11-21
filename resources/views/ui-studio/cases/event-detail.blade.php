{{-- UI Studio r6 / #933. Venue detail classes + shared event discussion. Local fixtures only. --}}
@php
    $isAppTheme = true;
    $primaryButton = 'button primary';
    $secondaryButton = 'button secondary';
    $chatEvent = (object) ['id' => 'studio-event'];
    $eventFinished = in_array($state, ['report','report-empty','report-owner','report-admin','report-guest','report-outsider','report-closed'], true);
    $reportOwner = in_array($state, ['report-owner','report-admin'], true);
    $canManageFeedback = $reportOwner || $state === 'discussion-owner';
    $discussionEnabled = $state !== 'discussion-closed';
    $reportCommentsEnabled = $state !== 'report-closed';
    $reportPublished = in_array($state, ['report','report-guest','report-outsider','report-closed'], true);
    // Demo identity: report-owner is olsen; report-admin is not a participant.
    // The guest's demo login is superadmin, who is on the completed-event roster.
    $reportViewerEligible = in_array($state, ['report','report-empty','report-owner','report-guest','report-closed'], true);
    $reportInitialLikes = $reportPublished ? 1 : 0;
    $hasRental = $eventFinished || in_array($state, ['booked','inactive'], true);
    $rentalActive = $state === 'booked';
    $eventTitle = 'Игра на м. Яхромская';
    $venueName = 'Кампус на Яхромской «Молния»';
    $courtName = 'Универсальный зал 1';
    $studioVenueUrl = 'https://mskba.ru/venues/11-kampus-na-yakhromskoy-molniya';
    $eventTime = ($eventFinished ? '19' : '22').' ноября 2026 · 12:00–13:30';
@endphp
@include('components.event-discussion-styles')
@include('ui-studio.cases.unified-chat-candidate')
@include('ui-studio.cases.event-detail-candidate')
<section class="container app-venue-page studio-event-page venue-play-dialog--app" data-studio-chat data-event-finished="{{ $eventFinished ? 'true' : 'false' }}" data-discussion-enabled="{{ $discussionEnabled ? 'true' : 'false' }}" data-report-comments-enabled="{{ $reportCommentsEnabled ? 'true' : 'false' }}" data-report-viewer-eligible="{{ $reportViewerEligible ? 'true' : 'false' }}" data-report-initial-likes="{{ $reportInitialLikes }}" aria-labelledby="studio-event-title">
    <div class="app-venue-page__hero-grid">
        <div class="app-venue-gallery panel" data-venue-gallery>
            <div class="app-venue-gallery__stage">
                <div class="studio-gallery-fallback"><svg aria-hidden="true"><use href="#ball"/></svg></div>
                <img class="app-venue-gallery__slide is-active" src="/build/assets/event-about-basketball-CPQ0p9SD.webp" alt="Демонстрационная фотография баскетбольной площадки" data-studio-hero-photo>
            </div>
        </div>
        <div class="app-venue-intro panel">
            <div class="app-venue-intro__badges"><span class="badge">Игра</span><span class="badge">Публичное</span>@if($eventFinished)<span class="badge studio-event-completed">Завершено <span class="studio-event-like-tally" title="Количество лайков"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#heart"/></svg><span data-report-like-count role="status" aria-live="polite" aria-label="Лайков: {{ $reportInitialLikes }}">{{ $reportInitialLikes }}</span></span></span>@endif</div>
            <div class="app-entity-heading"><h1 id="studio-event-title">{{ $eventTitle }}</h1></div>
            <div class="studio-event-time"><strong>{{ $eventTime }}</strong>@unless($rentalActive || $eventFinished)<span class="muted">Время предварительное</span>@endunless</div>
            <p class="app-venue-intro__copy">Хуперы любого уровня, главное желание</p>
            <div class="app-venue-interest-summary__profiles studio-event-audience">
                <div class="app-venue-interest-summary__avatars" data-studio-audience-avatars aria-hidden="true"></div>
                <a href="#studio-participants" class="app-venue-interest-summary__count studio-event-audience-count" data-studio-audience-count>2 участника</a>
            </div>
            <div class="app-venue-intro__actions">
                <button type="button" class="button primary large" data-event-participate aria-haspopup="dialog" @if($eventFinished) hidden @endif>Участвовать</button>
                <button type="button" class="button secondary large" data-event-open="studio-share" aria-haspopup="dialog">Поделиться</button>
            </div>
        </div>
    </div>
    <nav class="app-venue-page__anchors" aria-label="Разделы мероприятия" data-venue-section-nav>
        <a href="#studio-where">Где играем</a>
        <a href="#studio-rental">Аренда</a>
        <a href="#studio-participants">Участники</a>
        <a href="#studio-discussion">Обсуждение</a>
        <a href="#studio-report">Как это было</a>
    </nav>
    <div class="app-venue-page__columns">
        <div class="app-venue-page__sections">
            <section class="panel app-venue-block studio-event-where" id="studio-where">
                <header class="app-venue-block__header"><h2>Где играем</h2></header>
                <a class="studio-event-link studio-event-venue-name" href="{{ $studioVenueUrl }}" target="_blank" rel="noopener noreferrer">{{ $venueName }}</a>
                <p>{{ $courtName }}</p>
                <button type="button" class="app-venue-intro__address" data-venue-map-open aria-haspopup="dialog"><svg aria-hidden="true"><use href="#pin"/></svg><span>Москва, Лобненская улица, 13</span></button>
                <div class="app-venue-intro__metro"><span class="app-venue-intro__station"><span class="app-venue-intro__metro-dot" style="--metro-color:#a1a2a3"></span>Яхромская</span></div>
                <a class="button secondary" href="{{ $studioVenueUrl }}" target="_blank" rel="noopener noreferrer">К площадке</a>
            </section>
            <section class="panel app-venue-block" id="studio-rental">
                <header class="app-venue-block__header"><h2>Аренда</h2></header>
                @if($hasRental)
                <button type="button" class="app-venue-activity studio-rental-card" data-event-open="studio-rental-dialog" aria-haspopup="dialog">
                    <span class="app-venue-activity__meta"><span>{{ $eventTime }}</span><span class="badge {{ $rentalActive ? 'studio-rental-active' : '' }}">{{ $rentalActive ? 'Активна' : 'Неактивна' }}</span></span>
                    <strong class="app-venue-activity__title">{{ $courtName }}</strong>
                    <span class="app-venue-activity__description">{{ $venueName }}</span>
                </button>
                @else
                <p class="muted">Площадка пока не забронирована.</p>
                @endif
            </section>
            <section class="panel app-venue-block studio-event-roster" id="studio-participants">
                @include('components.event-discussion', ['chatEvent'=>$chatEvent,'chatCanRespond'=>false,'chatShowRoster'=>true])
            </section>
            <section class="panel app-venue-block studio-event-discussion" id="studio-discussion">
                <header class="app-venue-block__header"><h2>Обсуждение</h2></header>
            </section>
            @include('ui-studio.cases.event-report')
        </div>
        <aside class="app-venue-page__aside panel studio-event-aside" aria-label="Игры">
            @if($canManageFeedback)<button type="button" class="button secondary full" data-event-open="studio-feedback-settings">Редактировать мероприятие</button>@endif
            <div class="studio-event-games"><strong>Игры</strong><button type="button" class="app-venue-activity" data-event-open="studio-game-dialog"><strong class="app-venue-activity__title">Игра на м. Яхромская</strong><span class="app-venue-activity__description">Баскетбол · 12:00</span></button></div>
        </aside>
    </div>
</section>
@include('components.event-discussion-delete-dialog')
@include('ui-studio.cases.event-detail-dialogs')
@if($canManageFeedback) @include('ui-studio.cases.event-feedback-dialog') @endif
@include('ui-studio.cases.event-report-editor')
@include('ui-studio.cases.venue-map-preview', [
    'mapShowCurrentVenueLink' => true,
    'mapPreview' => ['name'=>$venueName, 'address'=>'Москва, Лобненская улица, 13', 'url'=>$studioVenueUrl],
])
<script>
// Split the actual shared discussion into the two page sections before mounting.
const eventRoot=document.querySelector('.studio-event-page');
const discussionPanel=eventRoot.querySelector('#studio-discussion');
discussionPanel.append(eventRoot.querySelector('[data-venue-play-feedback]'),eventRoot.querySelector('.venue-play-dialog__wall'));
</script>
@include('ui-studio.cases.unified-chat-fixture', ['studioChatMode'=>'discussion'])
@if($eventFinished) @include('ui-studio.cases.unified-chat-fixture', ['studioChatMode'=>'report']) @endif
@include('ui-studio.cases.event-detail-actions')
