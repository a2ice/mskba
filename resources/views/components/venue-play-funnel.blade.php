@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $secondaryButton = $isAppTheme ? 'button secondary' : 'btn btn--secondary';
    $viewerIdentityIds = auth()->user()?->canonical()->identityIds() ?? [];
    $upcomingVenueEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->with(['organizerActor.user', 'participants', 'venue.schedule', 'primaryGame'])
        ->where('venue_id', $venue->id)
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')->limit(20)->get();
@endphp
<dialog class="mskba-modal venue-play-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}"
    data-venue-play-dialog data-venue-id="{{ $venue->id }}"
    data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    aria-labelledby="venue-play-title">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="venue-play-title" data-venue-play-title>Хочу здесь играть</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-close aria-label="Закрыть">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
        </button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll" data-venue-play-step="list">
        <div class="venue-play-dialog__events">
            @forelse($upcomingVenueEvents as $upcoming)
                @php
                    $venueEventStart = $upcoming->starts_at?->setTimezone(
                        $upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow')
                    );
                @endphp
                <button type="button" class="venue-play-dialog__event" data-venue-play-preview="{{ $upcoming->id }}">
                    <span class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }}
                        @if($venueEventStart) · {{ $venueEventStart->format('d.m.Y H:i') }}@endif
                        @if($upcoming->booking_deferred) · время предварительное @endif
                    </span>
                    <strong>{{ $upcoming->title }}</strong>
                    <small data-venue-play-card-count>{{ $upcoming->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED)->count() }} пойдут · Подробнее</small>
                </button>
            @empty
                <p class="venue-play-dialog__empty">На этой площадке пока нет предстоящих мероприятий. Вы можете создать своё мероприятие, пригласить игроков или открыть его для всех, чтобы желающие могли присоединиться самостоятельно.@if($isAppTheme) А пока можно посмотреть, когда площадка свободна.@endif</p>
            @endforelse
        </div>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-list-footer @if($isAppTheme && $upcomingVenueEvents->isEmpty()) data-venue-play-empty-footer @endif>
        @if($isAppTheme && $upcomingVenueEvents->isEmpty())
            <button type="button" class="{{ $secondaryButton }}" data-venue-availability-open>Посмотреть занятость</button>
        @endif
        <button type="button" class="{{ $primaryButton }}" data-quick-event-open
            data-quick-event-venue-id="{{ $venue->id }}" data-venue-play-create>Создать мероприятие</button>
    </footer>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll" data-venue-play-step="detail" hidden>
        @foreach($upcomingVenueEvents as $upcoming)
            @php
                $participant = $upcoming->participants->first(fn ($member) => in_array((int) $member->user_id, $viewerIdentityIds, true));
                $currentStatus = $participant?->confirmation_version === $upcoming->participation_confirmation_version ? $participant?->status?->value : null;
                $organizer = $upcoming->organizerActor?->user?->canonical();
                $isMyEvent = $organizer && in_array((int) $organizer->id, $viewerIdentityIds, true);
                $venueEventStart = $upcoming->starts_at?->setTimezone($upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'));
            @endphp
            <article class="venue-play-dialog__detail" data-venue-play-pane="{{ $upcoming->id }}"
                data-event-status="{{ $currentStatus ?? '' }}"
                data-event-organizer="{{ $isMyEvent ? '1' : '0' }}"
                data-event-participation-url="{{ route('events.participation', $upcoming->routeIdentifier()) }}"
                data-event-wall-url="{{ route('events.wall.index', $upcoming->routeIdentifier()) }}"
                data-event-post-url="{{ route('events.wall.store', $upcoming->routeIdentifier()) }}"
                hidden>
                <div class="venue-play-dialog__summary">
                    <h3>{{ $upcoming->title }}</h3>
                    <p class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }} · {{ $venueEventStart?->format('d.m.Y H:i') ?? 'Время уточняется' }}@if($upcoming->booking_deferred) · время предварительное @endif</p>
                    <p><strong>Описание:</strong> {{ $upcoming->description ?: 'Описание пока не добавлено.' }}</p>
                </div>
                <section class="venue-play-dialog__roster" aria-label="Участники">
                    <h4 data-venue-play-roster-heading>Участники</h4>
                    <div class="venue-play-dialog__roster-surface" data-venue-play-roster-surface aria-busy="false">
                        <ul class="venue-play-dialog__participants mskba-scroll" data-venue-play-participants aria-label="Список участников"></ul>
                        <p class="muted" data-venue-play-roster-empty>Загружаем участников…</p>
                        <span class="image-upload-loading" data-venue-play-roster-loading role="status" aria-label="Обновляем участников" hidden>
                            <span class="image-upload-loading__spinner" aria-hidden="true"></span>
                        </span>
                    </div>
                    <button type="button" class="{{ $secondaryButton }}" data-venue-play-roster-retry hidden>Повторить загрузку</button>
                </section>
                    <div class="venue-play-dialog__rsvp" role="group" aria-label="Ответ на участие">
                        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="confirmed" data-venue-play-attendance>Пойду</button>
                        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="tentative">Подумаю</button>
                    </div>
                <p class="venue-play-dialog__feedback" data-venue-play-feedback role="status" hidden></p>
                <section class="venue-play-dialog__wall" aria-label="Обсуждение мероприятия">
                    <ol class="venue-play-dialog__messages" data-venue-play-messages aria-live="polite"></ol>
                    <p class="muted" data-venue-play-wall-empty>Загружаем обсуждение…</p>
                    <form data-venue-play-message-form hidden>
                        <textarea id="venue-play-message-{{ $upcoming->id }}" name="body" maxlength="200" rows="2" required
                            aria-label="Написать сообщение" placeholder="Написать сообщение"></textarea>
                        <div class="venue-play-dialog__message-footer">
                            <small data-venue-play-chars aria-label="Осталось символов">200</small>
                            <button type="button" class="venue-play-dialog__edit-cancel" data-venue-play-edit-cancel hidden aria-label="Отменить редактирование" title="Отменить редактирование"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
                            <button type="button" class="venue-play-dialog__send venue-play-dialog__delete" data-venue-play-delete hidden aria-label="Удалить сообщение" title="Удалить сообщение"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
                            <button type="submit" class="{{ $primaryButton }} venue-play-dialog__send" aria-label="Отправить сообщение" title="Отправить сообщение">
                                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15m-6-6 6 6-6 6"/></svg>
                            </button>
                        </div>
                    </form>
                    <p class="muted" data-venue-play-comment-notice hidden>Написать сообщение могут участники и организатор мероприятия.</p>
                </section>
            </article>
        @endforeach
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-detail-footer hidden>
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-back>Назад</button>
        @foreach($upcomingVenueEvents as $upcoming)
            <a class="{{ $primaryButton }}" data-venue-play-event-link="{{ $upcoming->id }}" href="{{ route('events.show', $upcoming->routeIdentifier()) }}" hidden>К мероприятию</a>
        @endforeach
    </footer>
</dialog>
<dialog class="mskba-modal venue-play-dialog venue-play-auth-notice {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}" data-venue-play-auth-notice
    aria-labelledby="venue-play-auth-title">
    <header class="mskba-modal__header venue-play-dialog__header"><h2 id="venue-play-auth-title">Нужна авторизация</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-auth-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll">
        <p>Чтобы продолжить, войди или зарегистрируйся. Мы сохраним выбранное мероприятие и твой ответ и вернём тебя к процессу.</p>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer">
        <button type="button" class="{{ $primaryButton }}" data-venue-play-auth-login>Войти</button>
    </footer>
</dialog>
<dialog class="mskba-modal venue-play-dialog venue-play-delete-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}" data-venue-play-delete-dialog aria-labelledby="venue-play-delete-title" aria-describedby="venue-play-delete-question">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="venue-play-delete-title">Удалить сообщение</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-delete-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body">
        <p id="venue-play-delete-question">Вы уверены, что хотите удалить?</p>
        <p data-venue-play-delete-error role="alert" hidden></p>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer">
        <button type="button" class="{{ $isAppTheme ? 'button danger' : 'btn btn--danger' }}" data-venue-play-delete-confirm>Да, удалить</button>
    </footer>
</dialog>
@once
<style>
.venue-play-dialog{
    width:min(690px,calc(100vw - 24px));max-width:calc(100vw - 24px);
    max-height:90dvh;padding:0;overflow:hidden;color:var(--text,#fff);
}
.venue-play-dialog[open]{display:flex;flex-direction:column}
.venue-play-dialog:not([open]){display:none}
.venue-play-dialog:not(.venue-play-dialog--app){
    border:1px solid var(--line,#59606a);border-radius:16px;
    background:var(--surface,#1b1f27);box-shadow:0 20px 85px #0008;
}
.venue-play-dialog:not(.venue-play-dialog--app)::backdrop{background:rgba(0,0,0,.64)}
.venue-play-dialog:not(.venue-play-dialog--app)[open]>.venue-play-dialog__header::before{
    content:"⠿";align-self:center;justify-self:center;
    color:var(--muted,#929aa4);font-size:1rem;line-height:1;pointer-events:none;
}
.venue-play-dialog__header{
    display:grid;grid-template-columns:16px minmax(0,1fr) 44px;
    align-items:center;gap:12px;padding:20px 22px;
    border-bottom:1px solid var(--line,#59606a);
    cursor:grab;touch-action:none;user-select:none;
}
.venue-play-dialog__header h2{margin:0;font-size:clamp(19px,2vw,25px);line-height:1.3;min-width:0}
.venue-play-dialog__header .venue-play-dialog__close{
    width:44px;height:44px;display:flex;align-items:center;justify-content:center;
    justify-self:end;border:1px solid var(--line,#59606a);
    border-radius:12px;background:var(--surface,#1b1f27);color:inherit;cursor:pointer;
}
.venue-play-dialog__close svg{
    width:20px;height:20px;fill:none;stroke:currentColor;
    stroke-width:2;stroke-linecap:round;
}
.venue-play-dialog__body{
    flex:1 1 auto;min-height:0;overflow-y:auto;padding:20px 22px;
    display:grid;gap:16px;align-content:start;
}
.venue-play-dialog__body[hidden],.venue-play-dialog__detail[hidden]{display:none}
.venue-play-dialog__events{display:grid;gap:10px}
.venue-play-dialog__event{
    display:grid;gap:6px;width:100%;text-align:left;padding:14px;
    border:1px solid var(--line,#5c6570);border-radius:12px;
    background:var(--field,#272b32);color:inherit;text-decoration:none;cursor:pointer;
}
.venue-play-dialog__event:hover,.venue-play-dialog__event:focus-visible{
    border-color:var(--accent,#ea7628);
}
.venue-play-dialog__event-meta{font-size:13px;color:var(--muted,#929aa4)}
.venue-play-dialog__event strong{line-height:1.35}
.venue-play-dialog__event small{color:var(--muted,#929aa4)}
.venue-play-dialog__empty{margin:0;padding:24px 14px;text-align:center;color:var(--muted,#929aa4)}
.venue-play-dialog__footer{
    display:flex;justify-content:flex-end;flex:0 0 auto;
    gap:12px;padding:16px 22px;border-top:1px solid var(--line,#59606a);
}
.venue-play-dialog__footer[data-venue-play-empty-footer]{justify-content:space-around;gap:12px;flex-wrap:wrap}
.venue-play-dialog__footer[hidden]{display:none}
.venue-play-dialog__footer :is(.button,.btn){min-height:44px}
.venue-play-dialog__detail{display:grid;gap:20px;min-width:0}
.venue-play-dialog__summary,.venue-play-dialog__roster{display:grid;gap:10px;min-width:0}
.venue-play-dialog__summary h3,.venue-play-dialog h4,.venue-play-dialog__summary p,.venue-play-dialog__roster p,.venue-play-dialog__wall p{margin:0}
.venue-play-dialog__summary h3{font-size:clamp(21px,2.4vw,26px);line-height:1.3;overflow-wrap:anywhere}
.venue-play-dialog__summary p{line-height:1.5;overflow-wrap:anywhere}
.venue-play-dialog__roster-surface{position:relative;min-width:0;min-height:30px;border-radius:10px}
.venue-play-dialog__participants{display:grid;gap:9px;max-height:200px;overflow-y:auto;overscroll-behavior:contain;list-style:none;margin:0;padding:2px 6px 2px 0}
.venue-play-dialog__participants:empty{display:none}
.venue-play-dialog__participant{display:flex;align-items:center;gap:10px;min-width:0;min-height:30px;font-size:14px}
.venue-play-dialog__participant-name{min-width:0;overflow-wrap:anywhere}
.venue-play-dialog__participant-status{display:inline-block;flex:0 0 10px;width:10px;height:10px;border-radius:50%;background:#d97706}
.venue-play-dialog__participant-status.is-confirmed{background:#16a34a}
.venue-play-dialog__participant-status.is-declined{background:#dc2626}
.venue-play-dialog__participant-star{flex:none;color:#eab308;width:17px;height:17px;display:grid;place-items:center}
.venue-play-dialog__participant-star svg{width:100%;height:100%;fill:currentColor;stroke:currentColor}
.venue-play-dialog__avatar{width:30px;height:30px;flex:0 0 30px;display:grid;place-items:center;overflow:hidden;border-radius:50%;background:var(--surface-raised,var(--field,#444))}
.venue-play-dialog__avatar img{width:100%;height:100%;object-fit:cover}
.venue-play-dialog__avatar svg{width:18px;height:18px;stroke:currentColor;fill:none}
.venue-play-dialog__roster-surface .image-upload-loading{min-height:30px;padding:0;background:rgba(8,10,8,.35);backdrop-filter:blur(2px)}
.venue-play-dialog__roster-surface .image-upload-loading__spinner{width:24px;height:24px;border-right-color:var(--accent,#ea7628)}
.venue-play-dialog [hidden]{display:none!important}
.venue-play-dialog__footer[data-venue-play-detail-footer]{justify-content:space-between;align-items:center}
.venue-play-dialog__footer[data-venue-play-detail-footer] :is(.button,.btn){width:auto;min-width:0;white-space:normal;text-align:center}
.venue-play-dialog__roster>button{justify-self:start}
.venue-play-dialog>[data-action-toast-modal]{position:absolute;inset:auto 12px 12px auto;z-index:40;width:min(320px,calc(100% - 24px));max-height:50%;overflow:auto}
.venue-play-dialog>[data-action-toast-modal] .app-action-toast{opacity:1;overflow-wrap:anywhere}
.notification-toast-region .app-action-toast--error{border-color:var(--danger,#ff8a8a)}
.venue-play-dialog__rsvp,.venue-play-dialog__message-footer{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.venue-play-dialog__rsvp .is-selected{outline:2px solid var(--accent,#ec792e);outline-offset:2px}
.venue-play-dialog__rsvp button{min-height:44px}
.venue-play-dialog__wall{border-top:1px solid var(--line,#59606a);padding-top:16px}
.venue-play-dialog__feedback{padding:10px;border-radius:8px;background:var(--field,#333)}
.venue-play-dialog__feedback.is-error{color:#f9a3a3}
.venue-play-dialog__wall{display:grid;gap:10px}
.venue-play-dialog__messages{display:grid;gap:10px;max-height:250px;overflow-y:auto;list-style:none;padding:0;margin:0}
.venue-play-dialog__messages li{padding:10px 12px;border-radius:8px;background:var(--field,#30343a);overflow-wrap:anywhere}
.venue-play-dialog__messages li:has(.venue-play-dialog__edit){position:relative;padding-right:48px}
.venue-play-dialog__edit,.venue-play-dialog__edit-cancel{display:inline-grid;place-items:center;min-width:36px;min-height:36px;border:0;background:transparent;color:inherit;cursor:pointer;border-radius:5px}
.venue-play-dialog__edit{position:absolute;right:4px;top:2px}
.venue-play-dialog__edit svg,.venue-play-dialog__edit-cancel svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.venue-play-dialog__edit:hover,.venue-play-dialog__edit:focus-visible,.venue-play-dialog__edit-cancel:hover,.venue-play-dialog__edit-cancel:focus-visible{color:var(--accent,#ea7628)}
.venue-play-dialog__edit-cancel{margin-left:auto}
.venue-play-dialog__wall form.is-editing{border-color:var(--accent,#ea7628)}
.venue-play-dialog__messages time{font-size:12px;opacity:.65;margin-left:9px}
.venue-play-dialog__messages li.is-system{opacity:.8}
.venue-play-dialog__messages li[data-rsvp-status]{opacity:1}
.venue-play-dialog__messages li[data-rsvp-status="confirmed"] p{color:var(--success,#7cdcab)}
.venue-play-dialog__messages li[data-rsvp-status="left"] p{color:var(--danger,#ff9398)}
.venue-play-dialog__messages li[data-rsvp-status="tentative"] p{color:var(--info,#8ccbff)}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="confirmed"] p{color:#166534}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="left"] p{color:#b91c1c}
html[data-appearance-mode="light"] .venue-play-dialog__messages li[data-rsvp-status="tentative"] p{color:#1e3a8a}
.venue-play-dialog__messages li.is-deleted{text-decoration:line-through;opacity:.6}
.venue-play-dialog__wall form{display:grid;gap:4px;padding:10px;border:1px solid var(--line,#cbd0d5);border-radius:5px;background:#fff;color:#18212b;min-width:0}
.venue-play-dialog__wall form:focus-within{border-color:var(--accent,#ea7628)}
.venue-play-dialog__wall textarea{width:100%;box-sizing:border-box;padding:0;min-height:56px;border:0;border-radius:0;outline:none;box-shadow:none;background:#fff;color:#18212b;resize:vertical}
.venue-play-dialog__wall textarea:focus{outline:none;box-shadow:none}
.venue-play-dialog__wall textarea::placeholder{color:#69717c}
.venue-play-dialog__message-footer{justify-content:space-between;flex-wrap:nowrap}
.venue-play-dialog__send{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;min-width:44px;padding:0}
.venue-play-dialog__send svg{width:22px;height:22px}
.venue-play-dialog__delete{border:0;border-radius:5px;background:#b91c1c;color:#fff;cursor:pointer}
.venue-play-dialog__delete:hover{background:#991b1b}
.venue-play-dialog__delete:disabled{opacity:.5;cursor:default}
.venue-play-delete-dialog{width:min(440px,calc(100vw - 24px))}
.venue-play-delete-dialog p{margin:0}
.venue-play-delete-dialog [data-venue-play-delete-error]{color:var(--danger,#dc2626)}
@media(max-width:540px){
    .venue-play-dialog__header,.venue-play-dialog__body{padding-inline:16px}
    .venue-play-dialog__footer{padding:16px}
    .venue-play-dialog__footer:not([data-venue-play-detail-footer]) :is(.button,.btn){width:100%}
}
</style>
@endonce
