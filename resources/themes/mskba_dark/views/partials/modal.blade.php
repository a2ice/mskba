<!-- @php
$modals = [
	'auth' => 'Вход на сайт',
	'auth-classic' => 'Вход на сайт (классический)',
	'venues' => 'Площадки',
];
@endphp

@foreach ($modals as $id => $title)

	@component('theme::partials.modal.layout', [
		'id' => $id,
	])
		@include('theme::partials.modal.views.' . $id)
	@endcomponent

@endforeach -->


@component('theme::partials.modal.layout', [
	'id' => 'auth-entry',
])
	@include('theme::partials.modal.views.auth')
@endcomponent

@component('theme::partials.modal.layout', [
	'id' => 'auth-entry-classic',
])
	@include('theme::partials.modal.views.auth-classic')
@endcomponent

@component('theme::partials.modal.layout', [
	'id' => 'venues',
])
	@include('theme::partials.modal.views.venues')
@endcomponent

@component('theme::partials.modal.layout', [
    'id' => 'embedded-entity-preview',
    'dialogClass' => 'venue-selector-preview-modal__dialog',
])
    <h2 class="modal_title" id="modal-title-embedded-entity-preview" data-entity-preview-title>Площадка</h2>
    <p class="venue-selector-preview__message" data-entity-preview-message>Загружаем информацию…</p>
    <article class="venue-selector-preview" data-entity-preview-content hidden>
        <div class="venue-selector-preview__image-wrap" data-entity-preview-image-wrap hidden>
            <img class="venue-selector-preview__image" src="" alt="" data-entity-preview-image>
        </div>
        <div class="venue-selector-preview__badges">
            <span class="venue-selector-preview__badge" data-entity-preview-type></span>
            <span class="venue-selector-preview__state" data-entity-preview-state></span>
        </div>
        <p class="venue-selector-preview__address" data-entity-preview-address></p>
        <p class="venue-selector-preview__metro" data-entity-preview-metro hidden></p>
        <p class="venue-selector-preview__hours" data-entity-preview-hours></p>
        <p class="venue-selector-preview__description" data-entity-preview-description hidden></p>
        <a class="btn" href="#" data-entity-preview-page>Открыть площадку</a>
    </article>
@endcomponent

@foreach (['games' => 'Играть', 'trainings' => 'Тренировки', 'teams' => 'Команды'] as $id => $title)
    @component('theme::partials.modal.layout', ['id' => $id])
        <h2 class="modal_title" id="modal-title-{{ $id }}">{{ $title }}</h2>
        <p class="modal-description">Функционал находится в разработке.</p>
    @endcomponent
@endforeach
