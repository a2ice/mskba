@props([
    'id',
    'defaultPanel' => null,
    'activePanel' => null,
    'openOnLoad' => false,
    'dialogClass' => null,
])

<div
    class="modal"
    data-modal="{{ $id }}"
    @if($defaultPanel) data-modal-default-panel="{{ $defaultPanel }}" @endif
    @if($activePanel) data-modal-active-panel="{{ $activePanel }}" @endif
    @if($openOnLoad) data-modal-open-on-load="1" @endif
    hidden
>
    <div
        class="modal__dialog{{ $dialogClass ? ' '.$dialogClass : '' }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title-{{ $id }}"
    >
        <button
            class="modal__close"
            type="button"
            aria-label="Закрыть окно"
            data-handler="modal"
            data-modal-action="close"
        >
            <span></span>
        </button>

        {{ $slot }}
    </div>
</div>
