@forelse ($conversationCards as $conversation)
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
@empty
    <p class="app-messages__rail-empty">Других диалогов пока нет.</p>
@endforelse
