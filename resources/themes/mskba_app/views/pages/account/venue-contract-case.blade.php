@extends('theme::layouts.account', ['title' => 'Полномочия: '.$venue->name])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Управление площадкой',
        'intro' => $venue->name.' · Подтверждение полномочий и контракты',
    ])
@endsection
@section('account-content')
    @include('account.partials.venue-contract-case')
@endsection
