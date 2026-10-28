<p class="muted">Использований: {{ $offer->used_count }}{{ $offer->max_total_uses ? ' / '.$offer->max_total_uses : '' }} · версия {{ $offer->terms_version }}</p>
<div class="app-group-offers__actions">
@if(in_array($offer->status, ['draft','paused'], true))
<form method="POST" action="{{ route('venues.interest-group.offers.transition',['venue'=>$venue->id,'offer'=>$offer->id,'action'=>'publish']) }}">
@csrf <button type="submit" class="button secondary">Опубликовать</button>
</form>
@endif
@if($offer->status === 'active')
<form method="POST" action="{{ route('venues.interest-group.offers.transition',['venue'=>$venue->id,'offer'=>$offer->id,'action'=>'pause']) }}">
@csrf <button type="submit" class="button secondary">Приостановить</button>
</form>
@endif
@if($offer->status !== 'revoked')
<form method="POST" action="{{ route('venues.interest-group.offers.transition',['venue'=>$venue->id,'offer'=>$offer->id,'action'=>'revoke']) }}">
@csrf <button type="submit" class="button secondary">Отозвать</button>
</form>
@endif
</div>
