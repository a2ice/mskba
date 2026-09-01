@php $title = 'Подтверждение явки · '.$round->booking->venue->name; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        <div class="alert alert-warning"><strong>Ответ не продлевает hold.</strong> Сбор автоматически закроется не позже {{ $round->deadline_at->format('d.m.Y H:i') }}.</div>

        <div class="card mb-4"><div class="card-body">
            <p><strong>Статус:</strong> {{ $round->status->label() }}</p>
            <p><strong>Ответы:</strong> пойду {{ $round->yes_count }}, не пойду {{ $round->no_count }}, возможно {{ $round->maybe_count }}, ожидаются {{ $round->pending_count }}.</p>
            <p><strong>Цель:</strong> {{ $round->minimum_yes_responses }} ответов «Пойду» @if($round->threshold_reached_at) — достигнута@endif</p>
            @if($isInvited && $round->status === \App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus::OPEN && $round->deadline_at->isFuture())
                <div class="venue-management-actions">
                    @foreach(\App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceResponseValue::cases() as $value)
                        <form method="POST" action="{{ route('venue-booking-attendance.respond', $round) }}">@csrf<input type="hidden" name="response" value="{{ $value->value }}"><button class="btn {{ $ownResponse?->response === $value ? 'btn--primary' : 'btn--secondary' }} btn--sm" type="submit">{{ $value->label() }}</button></form>
                    @endforeach
                </div>
            @endif
            @if($isOrganizer && $round->status === \App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus::OPEN)
                <form class="mt-3" method="POST" action="{{ route('venue-booking-attendance.close', $round) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Закрыть сбор</button></form>
            @endif
        </div></div>

        <div class="card"><div class="card-body">
            <h2 class="h4">Участники</h2>
            @if($canSeeResponses)
                <ul>@foreach($round->responses as $response)<li>{{ $response->user->username }} — {{ $response->response?->label() ?? 'Нет ответа' }}</li>@endforeach</ul>
            @else
                <p class="text-muted">Персональные ответы видны только организатору.</p>
            @endif
        </div></div>
    </div></section>
@endsection
