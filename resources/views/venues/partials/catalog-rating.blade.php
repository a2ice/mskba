@php
    $ratingValue = $rating ?? null;
    $ratingText = $ratingValue === null ? '' : number_format((float) $ratingValue, 1, '.', '');
    $ratingLabel = $ratingValue === null ? 'Еще не оценили' : 'Рейтинг площадки: '.$ratingText.' из 5';
@endphp
<span @class(['venue-catalog-card-rating', 'is-unrated' => $ratingValue === null]) data-venue-card-rating
    role="img" title="{{ $ratingLabel }}" aria-label="{{ $ratingLabel }}">{{ $ratingText }}</span>
