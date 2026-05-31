@extends('theme::layouts.app', ['title' => 'Площадки'])

@section('content')
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
@endsection
