@foreach ($timeline as $entry)
    @if ($entry['kind'] === 'system')
        <article class="app-messages__bubble app-messages__bubble--system" data-conversation-event="{{ $entry['key'] }}">
            <div class="app-messages__bubble-head"><strong>{{ $entry['heading'] }}</strong></div>
            <p class="app-messages__bubble-body">{{ $entry['body'] }}</p>
            <time class="app-messages__timestamp" datetime="{{ $entry['created_at']?->toIso8601String() }}">{{ $entry['created_at']?->format('d.m.Y H:i') }}</time>
        </article>
    @else
        @php($speaker = $entry['sender'])
        @php($speakerName = $speaker?->username ?: (trim(($speaker?->profile?->first_name ?? '').' '.($speaker?->profile?->last_name ?? '')) ?: 'Участник'))
        <article class="app-messages__bubble {{ $entry['mine'] ? 'app-messages__bubble--outgoing' : 'app-messages__bubble--incoming' }}"
                 data-conversation-event="{{ $entry['key'] }}">
            <div class="app-messages__bubble-head">
                @if($entry['mine'])
                    <strong>Вы</strong>
                @elseif($speaker)
                    <button type="button" class="app-messages__participant" data-direct-message-open
                            data-recipient-id="{{ $speaker->id }}" data-recipient-name="{{ $speakerName }}">{{ $speakerName }}</button>
                @else
                    <strong>Участник</strong>
                @endif
            </div>
            <p class="app-messages__bubble-body">{{ $entry['body'] }}</p>
            <time class="app-messages__timestamp" datetime="{{ $entry['created_at']?->toIso8601String() }}">{{ $entry['created_at']?->format('d.m.Y H:i') }}</time>
        </article>
    @endif
@endforeach
