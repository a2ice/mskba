@extends('theme::layouts.app', ['title' => 'Новый турнир'])

@section('content')
<section class="first-screen"><div class="inner py-5"><div class="event-card"><span class="eyebrow">Турнир</span><h1><span title="Создайте контейнер соревнования. Команды и матчи добавляются после сохранения.">Новый турнир</span></h1><form method="POST" action="{{ route('tournaments.store') }}" enctype="multipart/form-data">@csrf @include('theme::pages.tournaments.partials.form')</form></div></div></section>
@endsection
