@php
    $ratingValue = $rating ?? null;
    $ratingText = $ratingValue === null ? '—' : number_format((float) $ratingValue, 1, '.', '');
@endphp
<span class="venue-catalog-card-rating" data-venue-card-rating
    title="{{ $ratingValue === null ? 'Пока нет оценок игроков' : 'Рейтинг площадки: '.$ratingText.' из 5' }}"
    aria-label="{{ $ratingValue === null ? 'Пока нет оценок игроков' : 'Рейтинг площадки: '.$ratingText.' из 5' }}">{{ $ratingText }}</span>
