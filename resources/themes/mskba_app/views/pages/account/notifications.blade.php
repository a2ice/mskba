@extends('theme::layouts.account', ['title' => 'Уведомления'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Уведомления',
        'subtitle' => 'События, приглашения и сообщения, требующие вашего внимания.',
    ])
@endsection
