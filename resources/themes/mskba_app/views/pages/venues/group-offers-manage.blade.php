@extends('theme::layouts.app', ['title' => 'Промокоды площадки: '.$venue->name])
@section('content')
<section class="section app-venue-interest-group" aria-labelledby="manage-offers-heading">
<div class="container">
<header class="app-venue-interest-group__header">
<div>
<p class="eyebrow">Администрирование площадки</p>
<h1 id="manage-offers-heading">Промокоды и специальные предложения</h1>
<p class="muted">{{ $venue->name }} · Группа интересов площадки</p>
</div>
<a class="button secondary" href="{{ route('venues.interest-group', ['venue'=>$venue->id]) }}">Открыть публичную страницу группы</a>
</header>
<div class="panel app-venue-interest-group__content app-group-offers__manage">
<p>Здесь владелец, администратор площадки или сотрудник с правом управления промокодами выпускает и публикует предложения.</p>
<p class="muted">Коды в черновике и на паузе не показываются участникам. Действующий опубликованный код отображается на публичной странице группы, где его можно скопировать.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<h2>Все предложения</h2>
@forelse($visibleOffers as $offer)
<article class="app-group-offers__card">
<h3>{{ $offer->title }} <span class="badge">{{ ['draft'=>'Черновик','active'=>'Активен','paused'=>'Пауза','revoked'=>'Отозван'][$offer->status] ?? $offer->status }}</span></h3>
@if($offer->description)<p>{{ $offer->description }}</p>@endif
<p>Код: <strong><code>{{ $offer->code_ciphertext }}</code></strong></p>
<p>Скидка: @if($offer->discount_type === 'percent') {{ $offer->discount_value }}% @else {{ number_format($offer->discount_value / 100,2,',',' ') }} {{ $offer->currency }} @endif</p>
<p class="muted">С {{ $offer->valid_from->timezone($venueTimezone)->format('d.m.Y H:i') }}
@if($offer->valid_until) до {{ $offer->valid_until->timezone($venueTimezone)->format('d.m.Y H:i') }} @else · бессрочно @endif</p>
@include('theme::pages.venues.partials.group-offer-actions')
</article>
@empty
<p class="muted">Пока нет созданных промокодов.</p>
@endforelse
{{ $visibleOffers->links() }}
@include('theme::pages.venues.partials.group-offer-form')
</div>
</div>
</section>
@endsection
