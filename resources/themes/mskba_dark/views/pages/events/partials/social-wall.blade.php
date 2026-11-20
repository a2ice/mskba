@php
    $wallEntries = $eventWallEntries ?? collect();
    $wallPresenter = app(\App\Modules\Social\Application\Services\SocialWallService::class);
    $isViewer = $eventWallCanView ?? false;
    $canPost = $eventWallCanPost ?? false;
    $counts = $eventWallCounts ?? ['confirmed' => 0, 'tentative' => 0, 'left' => 0];
@endphp
<section class="event-social-wall" id="event-social-wall"
    data-event-social-wall
    data-wall-index-url="{{ route('events.wall.index', $event->routeIdentifier()) }}"
    data-wall-store-url="{{ route('events.wall.store', $event->routeIdentifier()) }}"
    data-wall-last-id="{{ $wallEntries->last()?->id ?? 0 }}"
    aria-labelledby="event-social-wall-title">
    <header class="event-social-wall__header">
        <div>
            <p class="event-social-wall__eyebrow">Мероприятие · общение</p>
            <h2 id="event-social-wall-title">Обсуждение и история</h2>
            <p class="event-social-wall__muted">
                Договоритесь о деталях игры. Изменения участия автоматически сохраняются в истории мероприятия.
            </p>
        </div>
        <a class="event-social-wall__back" href="{{ route('events.index') }}">Все мероприятия</a>
    </header>
    <div class="event-social-wall__stats" role="group" aria-label="Текущие ответы на участие">
        <span><strong data-wall-count="confirmed">{{ $counts['confirmed'] }}</strong> пойдут</span>
        <span><strong data-wall-count="tentative">{{ $counts['tentative'] }}</strong> думают</span>
        <span><strong data-wall-count="left">{{ $counts['left'] }}</strong> не пойдут</span>
    </div>
    <div class="event-social-wall__tabs" role="tablist" aria-label="Разделы общения">
        <button type="button" class="event-social-wall__tab is-active"
            id="event-wall-tab-chat" data-event-wall-tab="chat" role="tab"
            aria-controls="event-wall-panel-chat" aria-selected="true">Обсуждение</button>
        <button type="button" class="event-social-wall__tab"
            id="event-wall-tab-history" data-event-wall-tab="history" role="tab"
            aria-controls="event-wall-panel-history" aria-selected="false">История действий</button>
    </div>

    <div id="event-wall-panel-chat" data-event-wall-panel="chat"
        role="tabpanel" tabindex="0" aria-labelledby="event-wall-tab-chat">
        @if($isViewer)
            <ol class="event-social-wall__list" data-event-wall-list="chat" aria-live="polite">
                @forelse($wallEntries as $entry)
                    @php($display = $wallPresenter->present($entry))
                    <li class="event-social-wall__entry {{ $display['system'] ? 'is-system' : 'is-comment' }} {{ $display['deleted'] ? 'is-deleted' : '' }}"
                        data-event-wall-entry="{{ $entry->id }}">
                        <div class="event-social-wall__entry-head">
                            <strong>{{ $display['system'] ? 'Событие' : $display['actor'] }}</strong>
                            <time>{{ $display['date'] }}{{ $display['edited'] ? ' (изменено)' : '' }}</time>
                        </div>
                        <p class="event-social-wall__entry-text @if($display['deleted'] && !$display['system']) is-struck @endif">{{ $display['text'] }}</p>
                        @if($display['deleted'] && !$display['system'])
                            <small class="event-social-wall__muted">Сообщение удалено автором</small>
                        @elseif(!$display['system'] && $entry->actor_user_id === auth()->user()?->canonical()->id)
                            <button class="event-social-wall__delete" type="button"
                                data-wall-delete-url="{{ route('events.wall.delete', [$event->routeIdentifier(), $entry->id]) }}">Удалить</button>
                        @endif
                    </li>
                @empty
                    <li class="event-social-wall__empty" data-event-wall-empty>Сообщений пока нет. Начни обсуждение.</li>
                @endforelse
            </ol>
            @if($canPost)
                <form class="event-social-wall__form" data-event-wall-form>
                    @csrf
                    <label for="event-wall-message">Написать участникам</label>
                    <textarea id="event-wall-message" name="body" maxlength="200" rows="3"
                        placeholder="Например, где встречаемся и что взять с собой?" required></textarea>
                    <div class="event-social-wall__form-actions">
                        <span class="event-social-wall__muted"><span data-event-wall-chars>0</span>/200 · не более 3 действий в минуту</span>
                        <button type="submit" class="btn btn--primary">Отправить</button>
                    </div>
                </form>
            @else
                <p class="event-social-wall__muted">Написать смогут подтверждённые участники мероприятия. Для участия используй кнопки «Пойду» или «Думаю» выше.</p>
            @endif
        @else
            <p class="event-social-wall__muted">Обсуждение доступно участникам мероприятия.</p>
        @endif
    </div>

    <div id="event-wall-panel-history" data-event-wall-panel="history"
        role="tabpanel" tabindex="0" aria-labelledby="event-wall-tab-history" hidden>
        <ol class="event-social-wall__list" data-event-wall-list="history">
            @forelse($wallEntries->filter(fn ($entry) => $entry->kind !== 'comment') as $entry)
                @php($display = $wallPresenter->present($entry))
                <li class="event-social-wall__entry is-system" data-event-wall-entry="{{ $entry->id }}">
                    <div class="event-social-wall__entry-head">
                        <strong>Событие</strong><time>{{ $display['date'] }}{{ $display['edited'] ? ' (изменено)' : '' }}</time>
                    </div>
                    <p class="event-social-wall__entry-text">{{ $display['text'] }}</p>
                </li>
            @empty
                <li class="event-social-wall__empty" data-event-wall-empty>Изменений пока нет.</li>
            @endforelse
        </ol>
    </div>
    <p class="event-social-wall__feedback" role="status" data-event-wall-feedback aria-live="polite" hidden></p>
</section>
