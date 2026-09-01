@php $title = $coordination->title; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        @if($coordination->booking?->status->occupiesVenue())
            <div class="alert alert-success"><strong>{{ $coordination->booking->status->label() }}.</strong> Для этого сбора слот уже удерживается или подтверждён.</div>
        @else
            <div class="alert alert-warning"><strong>Время ещё не забронировано.</strong> Этот сбор показывает интерес участников и не удерживает слот площадки.</div>
        @endif

        <div class="card mb-4"><div class="card-body">
            <p><strong>Площадка:</strong> <a href="{{ route('venues.show', $coordination->venue->alias) }}">{{ $coordination->venue->name }}</a></p>
            <p><strong>Время:</strong> {{ $coordination->starts_at->format('d.m.Y H:i') }}–{{ $coordination->ends_at->format('d.m.Y H:i') }}</p>
            <p><strong>Статус:</strong> {{ $coordination->status->label() }}</p>
            @if($coordination->description)<p>{{ $coordination->description }}</p>@endif

            @auth
                <div class="venue-management-actions">
                    @if($coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN && !$isParticipant)
                        <form method="POST" action="{{ route('venue-rental-coordinations.join', $coordination) }}">@csrf<button class="btn btn--primary btn--sm" type="submit">Присоединиться</button></form>
                    @elseif($coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN && $isParticipant && !$isOrganizer)
                        <form method="POST" action="{{ route('venue-rental-coordinations.leave', $coordination) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Покинуть сбор</button></form>
                    @endif
                    @if($isOrganizer && $coordination->status === \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN)
                        <form method="POST" action="{{ route('venue-rental-coordinations.close', $coordination) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Закрыть сбор</button></form>
                    @endif
                    @if($isOrganizer && in_array($coordination->status, [\App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::OPEN, \App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus::CLOSED], true))
                        <form method="POST" action="{{ route('venue-rental-coordinations.convert', $coordination) }}">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="btn btn--primary btn--sm" type="submit">Перейти к аренде</button>
                        </form>
                    @endif
                </div>
            @endauth
        </div></div>

        <div class="card"><div class="card-body">
            <h2 class="h4">Заинтересованы: {{ $activeParticipants->count() }}</h2>
            @if($canSeeParticipants)
                <ul>@foreach($activeParticipants as $participant)<li>{{ $participant->user->username }}</li>@endforeach</ul>
            @else
                <p class="text-muted">Список виден только участникам сбора.</p>
            @endif
        </div></div>
    </div></section>
@endsection
