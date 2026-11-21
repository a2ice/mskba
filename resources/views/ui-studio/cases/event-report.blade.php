{{-- Published event report / empty state. Always present, including upcoming events. --}}
@php
    $studioReportPhoto = $reportPublished ? 'data:image/webp;base64,'.base64_encode(file_get_contents(resource_path('themes/mskba_dark/images/event-about-basketball.webp'))) : '';
@endphp
<section class="panel app-venue-block studio-event-report" id="studio-report" aria-labelledby="studio-report-title">
    <header class="app-venue-block__header">
        <h2 id="studio-report-title">Как это было</h2>
        @if($reportOwner)<button type="button" class="button secondary" data-event-open="studio-report-editor" data-report-edit>Добавить отчёт</button>@endif
    </header>
    <p class="muted" data-report-empty @if($reportPublished) hidden @endif>Материалов по данному мероприятию пока нет</p>
    <div class="studio-report-content" data-report-content @unless($reportPublished) hidden @endunless>
        <p class="studio-report-description" data-report-description @unless($reportPublished) hidden @endunless>@if($reportPublished)Всем спасибо за игру! Всё прошло весело и бодро. Отлично побегали, сыграли несколько интересных матчей. До встречи на площадке!@endif</p>
        <div class="studio-report-media" data-report-photos-section @unless($reportPublished) hidden @endunless>
            <h3>Фотоотчёт</h3>
            <div class="studio-report-grid" data-report-photos>
                @if($reportPublished)
                <button type="button" class="studio-report-tile" data-report-sample-photo><img src="{{ $studioReportPhoto }}" alt="Демонстрационное фото баскетбола" loading="lazy"></button>
                @endif
            </div>
        </div>
        <div class="studio-report-media" data-report-videos-section @unless($reportPublished) hidden @endunless>
            <h3>Видеоотчёт</h3>
            <div class="studio-report-grid" data-report-videos>
                @if($reportPublished)
                <button type="button" class="studio-report-tile studio-report-video" data-report-sample-video>
                    <img src="{{ $studioReportPhoto }}" alt="" loading="lazy"><span class="studio-report-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 5 12 7-12 7z"/></svg></span><span class="studio-report-media-label">Видеоотчёт</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @if($eventFinished)
    <div class="studio-report-chat" data-studio-report-chat>
        <h3>Комментарии</h3>
        <div class="venue-play-dialog__rsvp studio-report-reactions" role="group" aria-label="Оценка мероприятия">
            <button type="button" class="button secondary" data-report-reaction="like" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10v11H3V10zM7 10l5-7a2 2 0 0 1 2 2v5h5a2 2 0 0 1 2 2l-2 7a2 2 0 0 1-2 2H7"/></svg>Нравится</button>
            <button type="button" class="button secondary" data-report-reaction="dislike" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14V3H3v11zM7 14l5 7a2 2 0 0 0 2-2v-5h5a2 2 0 0 0 2-2l-2-7a2 2 0 0 0-2-2H7"/></svg>Не нравится</button>
        </div>
        @include('components.event-discussion', ['chatEvent'=>(object)['id'=>'studio-report'], 'chatCanRespond'=>false,'chatShowRoster'=>false])
    </div>
    @endif
</section>
<style>
.studio-event-report .app-venue-block__header{width:100%}
.studio-event-report .app-venue-block__header h2{margin:0}
.studio-event-report .app-venue-block__header .button{flex:none}
.studio-event-report p{margin:0}
.studio-report-chat{display:grid;gap:12px;padding-top:16px;border-top:1px solid var(--line);min-width:0}
.studio-report-chat h3{margin:0}
.studio-report-chat .venue-play-dialog__wall{border:0;padding:0;min-width:0}
.studio-report-reactions button{display:inline-flex;gap:7px;align-items:center}
.studio-report-reactions svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.studio-report-reactions button[aria-pressed="true"]{color:var(--accent);border-color:var(--accent);background:var(--surface-raised)}
.studio-report-chat li[data-reaction="like"] p{color:var(--success,#166534)}
.studio-report-chat li[data-reaction="dislike"] p{color:var(--danger,#b91c1c)}
.studio-report-content,.studio-report-media{display:grid;gap:12px;min-width:0}
.studio-report-content{gap:20px}
.studio-report-description{white-space:pre-wrap;overflow-wrap:anywhere;color:var(--text);line-height:1.6}
.studio-report-media h3{margin:0}
.studio-report-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px}
.studio-report-tile{position:relative;display:block;width:100%;aspect-ratio:4/3;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--surface-raised);padding:0;cursor:pointer;color:inherit;text-align:left}
.studio-report-tile img,.studio-report-tile video{width:100%;height:100%;object-fit:cover;display:block}
.studio-report-play{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:48px;height:48px;display:grid;place-items:center;background:#17212bcc;color:#fff;border-radius:50%}
.studio-report-play svg{width:21px;height:21px;fill:currentColor;stroke:none}
.studio-report-media-label{position:absolute;bottom:8px;left:8px;right:8px;color:#fff;background:#17212bbd;padding:5px 8px;border-radius:6px;font-size:12px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.studio-report-editor .mskba-modal__body label{display:grid;gap:8px;font-size:14px}
.studio-report-editor textarea{width:100%;resize:vertical;min-height:104px}
.studio-report-editor .studio-report-files{display:grid;gap:8px}
.studio-report-editor .studio-report-file-list{display:grid;gap:6px;list-style:none;margin:0;padding:0}
.studio-report-editor .studio-report-file-list li{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 8px;border:1px solid var(--line);border-radius:8px;font-size:13px;overflow-wrap:anywhere}
.studio-report-editor .studio-report-file-list .icon-button{flex:none;width:44px;height:44px}
.studio-report-editor .mskba-modal__footer{padding:16px 22px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.studio-report-viewer{width:min(800px,calc(100vw - 24px))}
.studio-report-viewer img,.studio-report-viewer video{display:block;max-width:100%;max-height:65dvh;margin:auto;border-radius:8px}
.studio-event-report [hidden],.studio-report-editor [hidden],.studio-report-viewer [hidden]{display:none!important}
@media(max-width:540px){.studio-report-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.studio-report-editor .mskba-modal__footer{padding:12px 16px}}
</style>
