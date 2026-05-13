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
