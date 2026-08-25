@php $title = 'Заявка на владение №'.$claim->id; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        <div class="card"><div class="card-body">
            <p><strong>Площадка:</strong> {{ $claim->venue->name }}</p>
            <p><strong>Статус:</strong> {{ $claim->status->label() }}</p>
            <p><strong>Отправлена:</strong> {{ $claim->submitted_at->format('d.m.Y H:i') }}</p>
            <h2 class="h5 mt-4">Подтверждение полномочий</h2>
            <p>{!! nl2br(e($claim->evidence)) !!}</p>
            @if($claim->decision_reason)
                <h2 class="h5 mt-4">Решение</h2>
                <p>{!! nl2br(e($claim->decision_reason)) !!}</p>
            @endif
            @if($claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING && auth()->user()->canonical()->isSameIdentity($claim->applicant_user_id))
                <form method="POST" action="{{ route('account.venue-ownership-claims.cancel', $claim) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn--secondary btn--sm">Отменить заявку</button>
                </form>
            @endif
        </div></div>
    </div></section>
@endsection
