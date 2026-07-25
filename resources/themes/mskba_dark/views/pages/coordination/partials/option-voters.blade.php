@if(! $poll->is_anonymous && $canSeeResults && $option->relationLoaded('selections'))
    @php
        $voters = $option->selections
            ->map(function ($selection) {
                $user = $selection->ballot?->user;
                $profile = $user?->profile;

                return trim(implode(' ', array_filter([
                    $profile?->first_name,
                    $profile?->last_name,
                ]))) ?: $user?->username;
            })
            ->filter()
            ->unique()
            ->values();
    @endphp
    @if($voters->isNotEmpty())
        <small class="coordination-option-voters">
            {{ $voters->join(', ') }}
        </small>
    @endif
@endif
