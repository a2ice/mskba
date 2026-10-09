@extends('theme::layouts.account', ['title' => 'Мои команды'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои команды',
        'subtitle' => 'Команды, в которых вы участвуете или которыми управляете.',
    ])
@endsection
