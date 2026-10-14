@php
    $isSystem = ($entry['kind'] ?? '') !== 'message';
    $isMine = !$isSystem && (int) ($entry['sender']?->id ?? 0) === (int) $viewer->id;
@endphp
<article class="app-messages__bubble {{ $isSystem ? 'app-messages__bubble--system' : ($isMine ? 'app-messages__bubble--outgoing' : 'app-messages__bubble--incoming') }}"
         data-chat-entry="{{ $entry['id'] }}" data-chat-date="{{ $entry['at']?->format('Y-m-d') }}">
    <div class="app-messages__bubble-head"><strong>{{ $isSystem ? 'Система MSKBA' : ($isMine ? 'Вы' : ($entry['sender']?->username ?? 'Участник')) }}</strong></div>
    @if (! empty($entry['source_path']))
        <div class="app-messages__message-source">
            <span>Страница обращения:</span>
            <a href="{{ $entry['source_path'] }}" target="_blank" rel="noopener noreferrer">{{ $entry['source_path'] }}</a>
        </div>
    @endif
    <p class="app-messages__bubble-body">{{ $entry['text'] }}</p>
    <time class="app-messages__timestamp" datetime="{{ $entry['at']?->toIso8601String() }}">{{ $entry['at']?->format('H:i') }}</time>
</article>
