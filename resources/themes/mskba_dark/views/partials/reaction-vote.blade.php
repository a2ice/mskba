@php
    /** @var \App\Modules\Reaction\Application\Data\ReactionSummary $summary */
    $currentReaction = $summary->viewerReaction?->value;
@endphp

<div
    class="reaction-vote{{ !empty($compact) ? ' reaction-vote--compact' : '' }}"
    data-reaction-widget
    data-reaction-url="{{ route('reactions.set', ['subjectType' => $subjectType, 'subjectId' => $subjectId]) }}"
    data-reaction-current="{{ $currentReaction }}"
    data-reaction-authenticated="{{ auth()->check() ? '1' : '0' }}"
>
    <button
        class="reaction-vote__button{{ $currentReaction === 1 ? ' is-active' : '' }}"
        type="button"
        data-reaction-value="1"
        aria-pressed="{{ $currentReaction === 1 ? 'true' : 'false' }}"
        aria-label="Нравится"
    >
        <i class="ti ti-thumb-up" aria-hidden="true"></i>
        <span data-reaction-count="likes">{{ $summary->likes }}</span>
    </button>

    <button
        class="reaction-vote__button reaction-vote__button--dislike{{ $currentReaction === -1 ? ' is-active' : '' }}"
        type="button"
        data-reaction-value="-1"
        aria-pressed="{{ $currentReaction === -1 ? 'true' : 'false' }}"
        aria-label="Не нравится"
    >
        <i class="ti ti-thumb-down" aria-hidden="true"></i>
        <span data-reaction-count="dislikes">{{ $summary->dislikes }}</span>
    </button>

    <span class="reaction-vote__hint" data-reaction-hint hidden>Войдите, чтобы оценить</span>
</div>
