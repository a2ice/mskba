@extends('theme::layouts.account', ['title' => 'Мои контракты'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои контракты',
        'intro' => 'Все ваши контракты, сгруппированные по типам сущностей и статусам.',
    ])
@endsection
@section('account-content')
    @include('account.partials.contracts-index')
@endsection
