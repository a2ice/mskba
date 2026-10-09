@extends('theme::layouts.account', ['title' => 'Роли в проекте'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Роли в проекте',
        'subtitle' => 'Ваши роли и возможности участия в проекте.',
    ])
@endsection
