@extends('theme::layouts.app', ['title' => 'Площадки'])

@section('content')
    <section id="venues" class="venues-section py-5">
        <div class="container">
            <div class="section-heading">
                <h1 class="mb-4">Площадки</h1>
            </div>

            <div class="section-content">
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
                            <a href="{{ route('venues.edit', $venue->id) }}" class="btn btn-primary">Редактировать</a>
                        @endif
                    <hr>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
