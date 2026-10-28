@extends('theme::layouts.section-sidebar', [
    'title' => 'Промокоды площадки',
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Промокоды: '.$venue->name,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
    <p>Создание и управление промокодами доступно владельцу, администратору или делегированному менеджеру площадки.</p>
    <p><a href="{{ route('venues.interest-group', ['venue' => $venue->id]) }}">Публичная страница группы</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    <div class="account-contracts-workspace">
        @forelse($visibleOffers as $offer)
            <article class="account-contracts-workspace__card">
                <h2>{{ $offer->title }} · {{ $offer->status }}</h2>
                <p>Код: <strong>{{ $offer->code_ciphertext }}</strong></p>
                <p>Скидка: {{ $offer->discount_type === 'percent' ? $offer->discount_value.'%' : number_format($offer->discount_value / 100, 2, ',', ' ').' '.$offer->currency }}</p>
                <p>С {{ $offer->valid_from->timezone($venueTimezone)->format('d.m.Y H:i') }} до {{ $offer->valid_until?->timezone($venueTimezone)->format('d.m.Y H:i') ?? 'бессрочно' }}</p>
                <div class="account-contracts-workspace__actions">
                    @foreach(['publish' => 'Опубликовать', 'pause' => 'Приостановить', 'revoke' => 'Отозвать'] as $action => $label)
                        @if(($action === 'publish' && in_array($offer->status, ['draft', 'paused'], true))
                            || ($action === 'pause' && $offer->status === 'active')
                            || ($action === 'revoke' && $offer->status !== 'revoked'))
                            <form method="POST" action="{{ route('account.venues.group-offers.transition', ['venue' => $venue->id, 'offer' => $offer->id, 'action' => $action]) }}">
                                @csrf <button class="account-contracts-workspace__secondary" type="submit">{{ $label }}</button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </article>
        @empty
            <p>Промокодов пока нет.</p>
        @endforelse
        {{ $visibleOffers->links() }}
    </div>
    <h2>Новый промокод</h2>
    {{-- Use the existing validated form; it shares the same routes in both themes. --}}
    @include('theme::pages.venues.partials.group-offer-form')
@endsection
