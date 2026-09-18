@php
    $searchUrl = $searchUrl ?? null;
    $options = $options ?? [];
    $minimumLength = $minimumLength ?? ($searchUrl ? 2 : 1);
    $required = $required ?? true;
    $selectedId = $selectedId ?? '';
    $selectedLabel = $selectedLabel ?? '';
    $initialMessage = $initialMessage ?? ($selectedId !== ''
        ? "Выбрано: {$selectedLabel}"
        : "Введите не менее {$minimumLength} символов.");
@endphp
<div
    data-entity-predictive-search
    data-minimum-length="{{ $minimumLength }}"
    data-required="{{ $required ? '1' : '0' }}"
    @if($searchUrl) data-search-url="{{ $searchUrl }}" @endif
>
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    <div class="predictive-search__input-wrap">
        <input
            id="{{ $id }}"
            class="form-control predictive-search__input"
            type="text"
            autocomplete="off"
            placeholder="{{ $placeholder }}"
            data-entity-predictive-input
            value="{{ $selectedLabel }}"
        >
        <button class="predictive-search__control" type="button" data-entity-predictive-clear @if($selectedId === '') hidden @endif aria-label="Очистить"></button>
        <div class="predictive-search__list d-none" role="listbox" data-entity-predictive-results>
            @foreach($options as $option)
                <button class="predictive-search__item" type="button" data-entity-predictive-option data-id="{{ $option['id'] }}" data-label="{{ $option['label'] }}">
                    <span class="predictive-search__label">{{ $option['label'] }}</span>
                    @if($option['meta'] ?? null)<span class="predictive-search__meta">{{ $option['meta'] }}</span>@endif
                </button>
            @endforeach
        </div>
    </div>
    <input type="hidden" name="{{ $name }}" value="{{ $selectedId }}" data-entity-predictive-value @if($required) required @endif>
    <p class="predictive-search__message text-muted" data-entity-predictive-message>{{ $initialMessage }}</p>
</div>
