<div class="coordination-results">
    @foreach($poll->options as $option)
        <div class="coordination-result">
            <span class="coordination-vote-option__copy">
                <span>{{ $option->label }}</span>
                @if($option->proposer)
                    <small class="coordination-option-proposer">Предложил {{ $option->proposer->profile?->first_name ?: $option->proposer->username }}</small>
                @endif
                @include('theme::pages.coordination.partials.option-voters', compact('poll', 'option', 'canSeeResults'))
            </span>
            <strong>{{ $canSeeResults ? $option->selections_count : '—' }}</strong>
        </div>
    @endforeach
</div>
