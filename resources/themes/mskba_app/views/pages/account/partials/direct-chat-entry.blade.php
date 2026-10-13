@if (in_array($message->kind, ['blocked', 'unblocked'], true))
    @php($byMe = (int) $message->sender_user_id === (int) $viewer->id)
    <div class="app-messages__direct-event app-messages__direct-event--{{ $message->kind }}"
        data-chat-entry="message-{{ $message->id }}" data-chat-date="{{ $message->created_at?->format('Y-m-d') }}"
        data-chat-kind="event" role="note">
        <span>{{ $message->kind === 'blocked'
            ? ($byMe ? 'Вы заблокировали пользователя.' : 'Пользователь заблокировал вас.')
            : ($byMe ? 'Вы разблокировали пользователя.' : 'Пользователь снял блокировку.') }}</span>
        <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('H:i') }}</time>
    </div>
@else
    @include('theme::pages.account.partials.chat-bubble', ['entry' => [
        'kind' => 'message', 'id' => 'message-'.$message->id,
        'text' => $message->body, 'at' => $message->created_at, 'sender' => $message->sender,
    ], 'viewer' => $viewer])
@endif
