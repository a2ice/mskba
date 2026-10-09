@extends('theme::layouts.account', ['title' => 'Контакты'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Контакты',
        'subtitle' => 'Способы связи и их подтверждение.',
    ])
@endsection
