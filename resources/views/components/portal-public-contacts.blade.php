@if($contacts !== [])
    <ul class="mskba-portal-contacts" aria-label="Контакты MSKBA">
        @foreach($contacts as $channel => $contact)
            <li><a href="{{ $contact['href'] }}" @if(in_array($channel, ['telegram', 'vk'], true)) target="_blank" rel="noopener noreferrer" @endif>{{ $contact['value'] }}</a></li>
        @endforeach
    </ul>
@endif
