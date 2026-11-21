<section class="panel app-venue-block" id="event-report">
    <header class="app-venue-block__header"><h2>Как это было</h2>
        @if($finished && $feedbackManager)<button type="button" class="button secondary" data-event-dialog-open="event-report-editor">{{ filled($event->result_description) || $reportMedia->isNotEmpty() ? 'Редактировать' : 'Добавить отчёт' }}</button>@endif
    </header>
    @if(!$finished || (!filled($event->result_description) && $reportMedia->isEmpty()))
        <p class="muted">Материалов по данному мероприятию пока нет</p>
    @else
        @if(filled($event->result_description))<p class="app-event-description">{{ $event->result_description }}</p>@endif
        @foreach(['image'=>'Фотоотчёт','video'=>'Видеоотчёт'] as $kind=>$label)
            @php($items = $reportMedia->filter(fn($m)=>str_starts_with((string)$m->mime,$kind.'/')))
            @if($items->isNotEmpty())
                <h3>{{ $label }}</h3>
                <div class="app-event-report-grid">@foreach($items as $media)
                    @php($mediaUrl = $media->collection === 'event_report_media' ? route('events.report.media', [$event->routeIdentifier(),$media->id]) : $media->publicUrl())
                    <button type="button" data-event-media-open="{{ $mediaUrl }}" data-media-kind="{{ $kind === 'video' ? 'video' : 'photo' }}" aria-label="{{ $label }}">
                        @if($kind === 'video')<video src="{{ $mediaUrl }}" muted playsinline preload="metadata"></video><span class="app-event-video-play">▶</span>
                        @else<img src="{{ $mediaUrl }}" alt="Фото мероприятия" loading="lazy">@endif
                    </button>
                @endforeach</div>
            @endif
        @endforeach
    @endif
    @if($finished && $eventWallCanView)
        <div class="app-event-report-chat venue-play-dialog--app" data-venue-play-page data-event-report-wall
            data-venue-play-pane="report-{{ $event->id }}" data-authenticated="{{ auth()->check()?'1':'0' }}"
            data-event-wall-url="{{ route('events.wall.index', ['event'=>$event->routeIdentifier(),'report'=>1]) }}"
            data-event-post-url="{{ route('events.wall.store', ['event'=>$event->routeIdentifier(),'report'=>1]) }}"
            data-reaction-url="{{ route('events.report.reaction', $event->routeIdentifier()) }}"
            data-can-react="{{ $feedback['can_react']?'1':'0' }}">
            <h3>Комментарии</h3>
            <div class="venue-play-dialog__rsvp app-event-report-reactions" role="group" aria-label="Оценка мероприятия">
            <button type="button" class="button secondary" data-report-reaction="like" aria-pressed="false" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10v11H3V10zM7 10l5-7a2 2 0 0 1 2 2v5h5a2 2 0 0 1 2 2l-2 7a2 2 0 0 1-2 2H7"/></svg>Нравится</button>
            <button type="button" class="button secondary" data-report-reaction="dislike" aria-pressed="false" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14V3H3v11zM7 14l5 7a2 2 0 0 0 2-2v-5h5a2 2 0 0 0 2-2l-2-7a2 2 0 0 0-2-2H7"/></svg>Не нравится</button>
            </div>
            @include('components.event-discussion',['chatEvent'=>$event,'chatCanRespond'=>false,'chatShowRoster'=>false,'chatComposerId'=>'event-report-message-'.$event->id,'chatComposerLabel'=>'Написать комментарий','primaryButton'=>'button primary','secondaryButton'=>'button secondary'])
        </div>
    @endif
</section>
