@extends('theme::layouts.account', ['title' => 'Заявка на управление'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Заявка на управление площадкой',
        'intro' => $claim->venue->name,
    ])
@endsection
@section('account-content')
    <div class="account-collection account-contracts-workspace" style="display:grid;gap:18px">
        <section class="account-collection__section" style="padding:24px">
            <h2>Заявка №{{ $claim->id }}</h2>
            <p>Статус: {{ $claim->status->label() }}</p>
            @if($claim->decision_reason)<p>Решение администрации: {{ $claim->decision_reason }}</p>@endif
            <p>Документы, переписка, повторная подача и подтверждённый контракт теперь доступны из общей карточки площадки.</p>
            <a class="account-contracts-workspace__primary" href="{{ route('account.venue-contracts.show', ['venue' => $claim->venue_id, 'claim' => $claim->public_id]) }}">Открыть карточку в «Моих контрактах»</a>
        </section>
    </div>
@endsection
