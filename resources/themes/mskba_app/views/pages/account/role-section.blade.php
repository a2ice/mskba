@extends('theme::layouts.account', ['title' => $heading])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $heading,
        'subtitle' => $subtitle,
    ])
@endsection
