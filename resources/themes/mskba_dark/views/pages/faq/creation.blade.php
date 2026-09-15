@extends('theme::layouts.section-sidebar', ['title' => $guide['heading'], 'contentTitle' => $guide['heading'], 'sectionId' => 'faq'])
@section('section-sidebar')
    <a class="nav-link" href="{{ route('faq.index') }}">Все инструкции FAQ</a>
@endsection
@section('section-content')
    @include('theme::pages.faq.search')

    <p class="lead">{{ $guide['intro'] }}</p>

    @include('theme::partials.creation-requirements', ['requirements' => $guide['requirements'] ?? []])

    <ol class="mt-4">
        @foreach($guide['steps'] as $step)
            <li class="mb-3">{{ $step }}</li>
        @endforeach
    </ol>
    <a class="btn btn--secondary-bordered" href="{{ route($guide['catalog']) }}">{{ $guide['catalog_label'] }}</a>
@endsection
