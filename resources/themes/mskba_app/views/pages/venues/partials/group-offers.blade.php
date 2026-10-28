<section class="panel app-venue-interest-group__content app-group-offers" aria-labelledby="group-offers-heading">
<h2 id="group-offers-heading">Специальные предложения</h2>
<p class="muted">Коды доступны подписчикам этой площадки. Применение скидки при бронировании появится в №300.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
@if($visibleOffers === null)
<p class="muted">Предложения доступны после входа и подписки на площадку.</p>
@else
@forelse($visibleOffers as $offer)
<article class="app-group-offers__card">
<h3>{{ $offer->title }}</h3>
@if($canManageOffers)<span class="badge">{{ $offer->status }}</span>@endif
@if($offer->description)<p>{{ $offer->description }}</p>@endif
<p>Код: <strong><code>{{ $offer->code_ciphertext }}</code></strong></p>
<p>Скидка:
@if($offer->discount_type === 'percent')
<strong>{{ $offer->discount_value }}%</strong>
@else
<strong>{{ number_format($offer->discount_value / 100, 2, ',', ' ') }} {{ $offer->currency }}</strong>
@endif
</p>
<p class="muted">С {{ $offer->valid_from->timezone($venueTimezone)->format('d.m.Y H:i') }}
@if($offer->valid_until) до {{ $offer->valid_until->timezone($venueTimezone)->format('d.m.Y H:i') }}
@else · бессрочно @endif</p>
@if($offer->minimum_amount_minor !== null)
<p class="muted">Минимальная стоимость: {{ number_format($offer->minimum_amount_minor / 100, 2, ',', ' ') }} {{ $offer->currency }}</p>
@endif
@if($offer->scope)
<p class="muted">Зона: {{ \App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::from($offer->scope)->label() }}</p>
@endif
@if($offer->venue_court_id)
<p class="muted">Предложение ограничено конкретным залом.</p>
@endif
@if($offer->max_total_uses !== null)<p class="muted">Всего применений не более {{ $offer->max_total_uses }}</p>@endif
@if($offer->max_uses_per_account !== null)<p class="muted">На аккаунт не более {{ $offer->max_uses_per_account }}</p>@endif
@if($canManageOffers)@include('theme::pages.venues.partials.group-offer-actions')@endif
</article>
@empty
<p class="muted">Пока нет доступных предложений.</p>
@endforelse
{{ $visibleOffers->links() }}
@endif
@if($canManageOffers)@include('theme::pages.venues.partials.group-offer-form')@endif
</section>
