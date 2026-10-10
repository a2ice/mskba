@extends('theme::layouts.account', ['title' => $heading])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $heading,
        'subtitle' => $subtitle,
    ])
@endsection

@section('account-content')

@endsection
