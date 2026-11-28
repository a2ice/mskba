@php($person = $publicMembers->get($membership->id))
@if($person)
    <div class="app-community-person">
        @include('theme::pages.participants.partials.public-avatar', ['avatarUrl' => $person['avatar_url'], 'avatarRestricted' => $person['avatar_restricted'], 'avatarPlaceholderUrl' => $person['avatar_placeholder_url'] ?? null])
        <div class="app-community-person__body">
            @if($person['url'])<a href="{{ $person['url'] }}"><strong>{{ $person['name'] }}</strong></a>
            @else<strong>{{ $person['name'] }}</strong>@endif
            @if(!empty($personLabel))<p class="muted">{{ $personLabel }}</p>@endif
        </div>
        @if(!empty($allowContact) && $person['recipient_id'])
            <button type="button" class="button secondary" data-direct-message-open data-recipient-id="{{ $person['recipient_id'] }}" data-recipient-name="{{ $person['name'] }}">Написать</button>
        @endif
    </div>
@endif
