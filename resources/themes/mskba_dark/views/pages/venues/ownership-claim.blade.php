@php $title = 'Заявка на владение площадкой'; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen">
        <div class="inner">
            @include('theme::partials.breadcrumbs')
            <div class="section-heading mb-4">
                <h1 class="section-title">{{ $title }}</h1>
                <p class="lead">{{ $venue->name }}</p>
            </div>

            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <div class="card mb-4">
                <div class="card-body">
                    <p class="mb-3">Опишите, на каком основании вы управляете площадкой, и укажите проверяемые контакты или реквизиты документов. Не публикуйте секретные данные.</p>
                    <form method="POST" action="{{ route('venues.ownership-claims.store', $venue) }}">
                        @csrf
                        <label class="form-label" for="ownershipEvidence">Подтверждение полномочий</label>
                        <textarea id="ownershipEvidence" name="evidence" class="form-control" rows="7" required minlength="20" maxlength="5000">{{ old('evidence') }}</textarea>
                        @error('evidence')<div class="alert alert-danger mt-2">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn--primary btn--sm mt-3">Отправить заявку</button>
                    </form>
                </div>
            </div>

            @if($claims->isNotEmpty())
                <h2 class="h4 mb-3">История заявок</h2>
                @foreach($claims as $claim)
                    <div class="card mb-3"><div class="card-body">
                        <a href="{{ route('account.venue-ownership-claims.show', $claim) }}">Заявка №{{ $claim->id }}</a>
                        · {{ $claim->status->label() }} · {{ $claim->submitted_at->format('d.m.Y H:i') }}
                    </div></div>
                @endforeach
            @endif
        </div>
    </section>
@endsection
