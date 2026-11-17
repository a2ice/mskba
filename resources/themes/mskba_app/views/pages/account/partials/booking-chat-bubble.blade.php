@php
    $systemMessage = $message->type === 'system';
    $ownMessage = !$systemMessage && $message->authorActor?->user_id === $actor->user_id;
@endphp
<article class="app-messages__bubble {{ $systemMessage ? 'app-messages__bubble--system' : ($ownMessage ? 'app-messages__bubble--outgoing' : 'app-messages__bubble--incoming') }}"
         data-rental-message-id="{{ $message->id }}" data-rental-message-uuid="{{ $message->public_id }}"
         data-rental-at="{{ $message->created_at->toIso8601String() }}">
    <div class="app-messages__bubble-head">
        <strong>{{ $systemMessage ? 'Система MSKBA' : ($ownMessage ? 'Вы' : ($message->authorActor?->user?->username ?? 'Участник')) }}</strong>
    </div>
    @if($message->body !== null && $message->body !== '')
        <p class="app-messages__bubble-body">{{ $message->body }}</p>
    @endif
    @if($message->attachment_path !== null)
        <a class="app-messages__action" href="{{ $isPreRequest
            ? route('account.venue-pre-requests.download', [$pre, $message])
            : route('account.venue-bookings.conversation.attachment', [$booking, $message]) }}">
            Скачать: {{ $message->attachment_name }}
        </a>
    @endif
    <time class="app-messages__timestamp" datetime="{{ $message->created_at->toIso8601String() }}">
        {{ $message->created_at->format('d.m.Y H:i') }}
    </time>
</article>
