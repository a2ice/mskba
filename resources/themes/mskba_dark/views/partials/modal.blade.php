@php
	$activeAuthPanel = request()->routeIs('register') ? 'auth-register' : 'auth-login';
@endphp

@component('theme::partials.modal.layout', [
	'id' => 'auth-entry',
	'defaultPanel' => 'auth-login',
	'activePanel' => $activeAuthPanel,
])
	@include('theme::partials.modal.views.auth')
@endcomponent
