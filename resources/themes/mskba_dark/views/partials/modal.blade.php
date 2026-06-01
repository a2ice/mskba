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
