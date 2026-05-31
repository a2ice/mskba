@php
    $title = isset($title) ? $title : 'Модальное окно';
@endphp

<div class="partial-wrapper partial-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Модальное окно: {{ $title }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            {{ isset($slot) ? $slot : '' }}
        </div>
    </div>
</div>