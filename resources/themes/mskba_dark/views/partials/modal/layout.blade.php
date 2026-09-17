@props([
    'id',
    'defaultPanel' => null,
    'activePanel' => null,
    'openOnLoad' => false,
    'dialogClass' => null,
    'persistInUrl' => true,
])

<div
    class="modal"
    data-modal="{{ $id }}"
    @if($defaultPanel) data-modal-default-panel="{{ $defaultPanel }}" @endif
    @if($activePanel) data-modal-active-panel="{{ $activePanel }}" @endif
    @if($openOnLoad) data-modal-open-on-load="1" @endif
    @unless($persistInUrl) data-modal-persist-url="false" @endunless
    hidden
>
    <div
        class="modal__dialog{{ $dialogClass ? ' '.$dialogClass : '' }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title-{{ $id }}"
        tabindex="-1"
    >
        <header class="modal__header">
            <div class="modal__heading" data-modal-heading aria-hidden="true">
                <div class="modal_title" data-modal-title></div>
            </div>

            <div class="modal__window-actions">
                <button
                    class="modal__window-action modal__minimize"
                    type="button"
                    aria-label="Свернуть окно"
                    title="Свернуть"
                    data-handler="modal"
                    data-modal-action="minimize"
                >
                    <i class="ti ti-minus" aria-hidden="true"></i>
                </button>
                <button
                    class="modal__window-action modal__close"
                    type="button"
                    aria-label="Закрыть окно"
                    title="Закрыть"
                    data-handler="modal"
                    data-modal-action="close"
                >
                    <i class="ti ti-x" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="modal__body" data-modal-body tabindex="0">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="modal__footer" data-modal-footer-container>
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
