@extends('theme::layouts.account', ['title' => 'Интересуются · '.$venue->name])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Интересуются', 'intro' => $venue->name])
@endsection
@section('account-content')
    @include('account.partials.venue-audience')
@endsection
