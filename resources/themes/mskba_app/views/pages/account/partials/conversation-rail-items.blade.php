@php
    $conversationGroups = app(\App\Modules\Notification\Presentation\Presenters\ConversationGroupPresenter::class)
        ->groups(collect($conversationCards));
@endphp
@forelse ($conversationGroups as $group)
    @php
        $hasActive = $group['items']->contains(fn (array $item): bool => (string) $item['id'] === (string) $activeConversationId);
    @endphp
    <details class="app-messages__rail-group" data-conversation-group="{{ $group['key'] }}"
             data-group-active="{{ $hasActive ? 'true' : 'false' }}" open>
        <summary class="app-messages__rail-group-summary">
            <span class="app-messages__rail-group-label">{{ $group['label'] }} <span class="app-messages__group-count">{{ $group['items']->count() }}</span></span>
            @if ($group['unread_count'] > 0)
                <span class="app-messages__unread" aria-label="Непрочитанных сообщений в группе: {{ $group['unread_count'] }}">{{ $group['unread_count'] > 99 ? '99+' : $group['unread_count'] }}</span>
            @endif
            <span class="app-messages__group-chevron" aria-hidden="true"></span>
        </summary>
        <div class="app-messages__rail-group-items">
@foreach ($group['items'] as $conversation)
    <a href="{{ $conversation['url'] }}"
       class="app-messages__rail-item{{ (string) $activeConversationId === (string) $conversation['id'] ? ' is-active' : '' }}"
       @if ((string) $activeConversationId === (string) $conversation['id']) aria-current="page" @endif
       data-rail-conversation="{{ $conversation['id'] }}">
        <span class="app-messages__rail-heading">
            <strong>{{ $conversation['name'] }}</strong>
            @if ($conversation['unread_count'] > 0)
                <span class="app-messages__unread" aria-label="Непрочитанных: {{ $conversation['unread_count'] }}">{{ $conversation['unread_count'] > 99 ? '99+' : $conversation['unread_count'] }}</span>
            @endif
        </span>
        <span class="app-messages__rail-excerpt">{{ \Illuminate\Support\Str::limit($conversation['preview'], 72) }}</span>
        @if ($conversation['date'])
            <time datetime="{{ $conversation['date']->toIso8601String() }}">{{ $conversation['date']->format('d.m H:i') }}</time>
        @endif
    </a>
@endforeach
        </div>
    </details>
@empty
    <p class="app-messages__rail-empty">Других диалогов пока нет.</p>
@endforelse
