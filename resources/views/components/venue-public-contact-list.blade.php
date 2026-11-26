<ul class="venue-public-contact-list">
    @foreach($contacts as $contact)
        <li>
            <a href="{{ $contact['href'] }}">{{ $contact['value'] }}</a>
            @if(filled($contact['label']))
                <small class="venue-public-contact-list__label">{{ $contact['label'] }}</small>
            @endif
        </li>
    @endforeach
</ul>
