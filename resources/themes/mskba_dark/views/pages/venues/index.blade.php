@php 
    $title = 'Площадки';
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')

<section class="venues-section first-screen">

    <div class="inner">
        <div class="mb-3">
            @include('theme::partials.breadcrumbs')
        </div>

        <div class="section-heading mb-3">
            <h1 class="section-title">{{ $title }}</h1>
            <p class="lead">Находите и добавляйте баскетбольные площадки по всей Москве и области</p>
        </div>

        <div class="section-content">
            @if ($venues === [])
                <div class="alert alert-info">
                    Площадки пока не назначены.
                </div>
            @else
                @foreach ($venues as $venue)
                    <div class="venue-item mb-5">
                        <h5>
                            <a href="{{ route('venues.show', $venue->alias) }}">
                                {{ $venue->name }}
                            </a>
                        </h5>
                        <p>Статус: {{ $venue->status }}</p>
                        <p>Описание: {{ $venue->description }}</p>
                        @if ($venue->canEdit)
                            <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn-primary">Редактировать</a>
                        @endif
                    <hr>
                    </div>
                @endforeach
            @endif
        </div>

    </div>
</section>
@endsection
