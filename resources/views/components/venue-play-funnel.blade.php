@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $secondaryButton = $isAppTheme ? 'button secondary' : 'btn btn--secondary';
    $viewerIdentityIds = auth()->user()?->canonical()->identityIds() ?? [];
    $upcomingVenueEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->with(['organizerActor.user.profile', 'participants.user.profile', 'venue.schedule', 'primaryGame'])
        ->where('venue_id', $venue->id)
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')->limit(20)->get();
@endphp
<dialog class="venue-play-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}"
    data-venue-play-dialog data-venue-id="{{ $venue->id }}"
    data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    aria-labelledby="venue-play-title">
    <header class="venue-play-dialog__header">
        <div>
            <p class="venue-play-dialog__eyebrow">MSKBA · играем вместе</p>
            <h2 id="venue-play-title" data-venue-play-title>Хочу здесь играть</h2>
        </div>
        <button type="button" class="venue-play-dialog__close" data-venue-play-close aria-label="Закрыть">×</button>
    </header>
    <div class="venue-play-dialog__body mskba-scroll" data-venue-play-step="list">
        <p class="muted">Выбери ближайшее мероприятие или организуй своё на этой площадке.</p>
        <h3>Текущие мероприятия</h3>
        <div class="venue-play-dialog__events">
            @forelse($upcomingVenueEvents as $upcoming)
                @php($venueEventStart = $upcoming->starts_at->setTimezone($upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow')))
                <button type="button" class="venue-play-dialog__event" data-venue-play-preview="{{ $upcoming->id }}">
                    <span class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }} · {{ $venueEventStart->format('d.m.Y H:i') }}</span>
                    <strong>{{ $upcoming->title }}</strong>
                    <small>{{ $upcoming->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED)->count() }} пойдут · Подробнее</small>
                </button>
            @empty
                <p class="venue-play-dialog__empty">На этой площадке пока нет предстоящих мероприятий.</p>
            @endforelse
        </div>
        <button type="button" class="{{ $primaryButton }}" data-quick-event-open data-quick-event-venue-id="{{ $venue->id }}" data-venue-play-create>Создать мероприятие</button>
    </div>
    <div class="venue-play-dialog__body mskba-scroll" data-venue-play-step="detail" hidden>
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-back>Назад к мероприятиям</button>
        @foreach($upcomingVenueEvents as $upcoming)
            @php
                $participant = $upcoming->participants->first(fn ($member) => in_array((int) $member->user_id, $viewerIdentityIds, true));
                $currentStatus = $participant?->confirmation_version === $upcoming->participation_confirmation_version ? $participant?->status?->value : null;
                $organizer = $upcoming->organizerActor?->user?->canonical();
                $isMyEvent = $organizer && in_array((int) $organizer->id, $viewerIdentityIds, true);
                $venueEventStart = $upcoming->starts_at->setTimezone($upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'));
            @endphp
            <article class="venue-play-dialog__detail" data-venue-play-pane="{{ $upcoming->id }}"
                data-event-status="{{ $currentStatus ?? '' }}"
                data-event-organizer="{{ $isMyEvent ? '1' : '0' }}"
                data-event-participation-url="{{ route('events.participation', $upcoming->routeIdentifier()) }}"
                data-event-wall-url="{{ route('events.wall.index', $upcoming->routeIdentifier()) }}"
                data-event-post-url="{{ route('events.wall.store', $upcoming->routeIdentifier()) }}"
                hidden>
                <p class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }} · {{ $venueEventStart->format('d.m.Y H:i') }}</p>
                <h3>{{ $upcoming->title }}</h3>
                <p>{{ $upcoming->description ?: 'Описание пока не добавлено.' }}</p>
                <p><strong>Организатор:</strong>
                    @if($organizer?->username)
                        <a href="{{ route('users.show', $organizer->username) }}">{{ $organizer->username }}</a>
                    @else
                        Организатор
                    @endif
                </p>
                <h4>Участники</h4>
                <div class="venue-play-dialog__participants">
                    @forelse($upcoming->participants->filter(fn ($member) => $member->status !== \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::LEFT && $member->user && ! $member->user->trashed()) as $member)
                        @php
                            $person = $member->user->canonical();
                            $nickname = $person->username ?: 'Игрок';
                            $photo = $person->profile?->avatarUrl();
                        @endphp
                        <div class="venue-play-dialog__participant">
                            <span class="venue-play-dialog__avatar">
                                @if($photo)<img src="{{ $photo }}" alt="" loading="lazy">
                                @else{{ mb_strtoupper(mb_substr($nickname, 0, 1)) }}@endif
                            </span>
                            @if($person->username)<a href="{{ route('users.show', $person->username) }}">{{ $nickname }}</a>
                            @else<span>{{ $nickname }}</span>@endif
                            @if($member->status === \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::TENTATIVE)
                                <small>думает</small>
                            @endif
                        </div>
                    @empty
                        <p class="muted">Участников пока нет.</p>
                    @endforelse
                </div>
                @if(!$isMyEvent)
                    <div class="venue-play-dialog__rsvp" role="group" aria-label="Ответ на участие">
                        <button type="button" class="{{ $primaryButton }}" data-venue-play-rsvp="confirmed">Пойду</button>
                        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="tentative">Подумаю</button>
                        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="left">Не пойду</button>
                    </div>
                @else
                    <p class="muted">Ты организатор. Участием можно управлять на странице мероприятия.</p>
                @endif
                <p class="venue-play-dialog__feedback" data-venue-play-feedback role="status" hidden></p>
                <section class="venue-play-dialog__wall" aria-label="Обсуждение мероприятия">
                    <h4>Обсуждение и действия</h4>
                    <ol class="venue-play-dialog__messages" data-venue-play-messages aria-live="polite"></ol>
                    <p class="muted" data-venue-play-wall-empty>Загружаем обсуждение…</p>
                    <form data-venue-play-message-form hidden>
                        <label for="venue-play-message-{{ $upcoming->id }}">Написать сообщение</label>
                        <textarea id="venue-play-message-{{ $upcoming->id }}" name="body" maxlength="200" rows="2" required
                            placeholder="Например, где встречаемся?"></textarea>
                        <div class="venue-play-dialog__message-footer">
                            <small><span data-venue-play-chars>0</span>/200 · не более 3 действий в минуту</small>
                            <button type="submit" class="{{ $primaryButton }}">Отправить</button>
                        </div>
                    </form>
                    <p class="muted" data-venue-play-comment-notice>Написать сообщение смогут участники мероприятия.</p>
                </section>
                <a class="{{ $secondaryButton }}" href="{{ route('events.show', $upcoming->routeIdentifier()) }}">Полная страница мероприятия</a>
            </article>
        @endforeach
    </div>
</dialog>
<dialog class="venue-play-dialog venue-play-auth-notice" data-venue-play-auth-notice
    aria-labelledby="venue-play-auth-title">
    <header class="venue-play-dialog__header"><h2 id="venue-play-auth-title">Нужна авторизация</h2>
        <button type="button" class="venue-play-dialog__close" data-venue-play-auth-close aria-label="Закрыть">×</button>
    </header>
    <div class="venue-play-dialog__body">
        <p>Чтобы продолжить, войди или зарегистрируйся. Мы сохраним выбранное мероприятие и твой ответ и вернём тебя к процессу.</p>
        <div class="venue-play-dialog__message-footer">
            <button type="button" class="{{ $secondaryButton }}" data-venue-play-auth-close>Позже</button>
            <button type="button" class="{{ $primaryButton }}" data-venue-play-auth-login>Войти</button>
        </div>
    </div>
</dialog>
@once
<style>
.venue-play-dialog{width:min(690px,calc(100vw - 24px));max-width:100%;max-height:92dvh;padding:0;overflow:hidden;border:1px solid var(--line,#59606a);border-radius:16px;background:var(--surface,#1b1f27);color:var(--text,#fff)}
.venue-play-dialog::backdrop{background:rgba(0,0,0,.64)}
.venue-play-dialog__header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line,#59606a)}
.venue-play-dialog__header h2{margin:0;font-size:clamp(19px,2vw,25px)}
.venue-play-dialog__eyebrow{font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.7}
.venue-play-dialog__close{font-size:30px;line-height:1;color:inherit;background:transparent;border:0;cursor:pointer}
.venue-play-dialog__body{display:grid;gap:16px;padding:20px 22px;max-height:calc(92dvh - 85px);overflow:auto}
.venue-play-dialog__body[hidden],.venue-play-dialog__detail[hidden]{display:none}
.venue-play-dialog__body h3,.venue-play-dialog__body h4{margin:0}
.venue-play-dialog__events{display:grid;gap:9px}
.venue-play-dialog__event{display:grid;gap:6px;width:100%;text-align:left;padding:14px;border:1px solid var(--line,#5c6570);border-radius:11px;background:var(--field,#272b32);color:inherit;cursor:pointer}
.venue-play-dialog__event:hover,.venue-play-dialog__event:focus-visible{border-color:var(--accent,#ea7628)}
.venue-play-dialog__event-meta{font-size:13px;opacity:.77}
.venue-play-dialog__event small{opacity:.7}
.venue-play-dialog__empty{padding:24px 14px;text-align:center;opacity:.8}
.venue-play-dialog__participants{display:flex;flex-wrap:wrap;gap:12px}
.venue-play-dialog__participant{display:flex;align-items:center;gap:6px;font-size:14px}
.venue-play-dialog__participant small{opacity:.7}
.venue-play-dialog__avatar{width:35px;height:35px;display:grid;place-items:center;overflow:hidden;border-radius:50%;background:var(--field,#444)}
.venue-play-dialog__avatar img{width:100%;height:100%;object-fit:cover}
.venue-play-dialog__rsvp,.venue-play-dialog__message-footer{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.venue-play-dialog__rsvp .is-selected{outline:2px solid var(--accent,#ec792e);outline-offset:2px}
.venue-play-dialog__feedback{padding:10px;border-radius:8px;background:var(--field,#333)}
.venue-play-dialog__feedback.is-error{color:#f9a3a3}
.venue-play-dialog__wall{display:grid;gap:10px}
.venue-play-dialog__messages{display:grid;gap:10px;max-height:250px;overflow-y:auto;list-style:none;padding:0;margin:0}
.venue-play-dialog__messages li{padding:10px 12px;border-radius:8px;background:var(--field,#30343a);overflow-wrap:anywhere}
.venue-play-dialog__messages time{font-size:12px;opacity:.65;margin-left:9px}
.venue-play-dialog__messages li.is-system{opacity:.8}
.venue-play-dialog__messages li.is-deleted{text-decoration:line-through;opacity:.6}
.venue-play-dialog__wall form{display:grid;gap:8px}
.venue-play-dialog__wall textarea{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;min-height:70px;border:1px solid var(--line,#59606a);background:var(--field,#282d33);color:inherit}
.venue-play-dialog__message-footer{justify-content:space-between}
@media(max-width:540px){.venue-play-dialog__header,.venue-play-dialog__body{padding-inline:14px}.venue-play-dialog__rsvp>*{flex:1}}
</style>
@endonce
