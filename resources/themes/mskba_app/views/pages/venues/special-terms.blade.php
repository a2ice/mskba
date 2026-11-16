@extends('theme::layouts.account', ['title' => 'Специальные условия · '.$venue->name])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => $venue->name, 'subtitle' => 'Специальные условия'])
@endsection
@section('account-content')
<div class="app-rental-pricing">
    <a class="button secondary" href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}">К площадке</a>
    @include('account.partials.special-terms-content')
</div>
@endsection
