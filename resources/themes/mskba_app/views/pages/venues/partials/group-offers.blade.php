<section class="panel app-venue-interest-group__content app-group-offers" aria-labelledby="group-offers-heading">
<h2 id="group-offers-heading">Специальные предложения</h2>
<p class="muted">Действующие предложения для подписчиков площадки. Скопируйте код для будущего бронирования; применение скидки будет добавлено в №300.</p>
@forelse($visibleOffers as $offer)
<article class="app-group-offers__card">
<h3>{{ $offer->title }}</h3>
@if($offer->description)<p>{{ $offer->description }}</p>@endif
<div class="app-group-offers__copy"><span>Код: <strong><code data-group-offer-code>{{ $offer->code_ciphertext }}</code></strong></span>
<button type="button" class="button secondary" data-group-offer-copy aria-label="Скопировать промокод {{ $offer->title }}">Скопировать</button><span role="status" class="app-group-offers__copy-result" data-group-offer-copy-result aria-live="polite"></span></div>
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
</article>
@empty
<p class="muted">Пока нет доступных предложений.</p>
@endforelse
{{ $visibleOffers->links() }}
</section>
