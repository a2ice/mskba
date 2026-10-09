@extends('theme::layouts.account', ['title' => 'Мои секции'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои секции',
        'subtitle' => 'Секции и тренировки, связанные с вашим аккаунтом.',
    ])
@endsection
