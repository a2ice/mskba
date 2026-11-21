@php
    $mapPreview = ['name'=>'Кампус на Яхромской «Молния»','address'=>'Москва, Лобненская улица, 13','url'=>'https://mskba.ru/venues/11-kampus-na-yakhromskoy-molniya'];
@endphp
<main class="container" style="padding-block:32px">
    <div class="panel app-venue-intro">
        <div class="app-entity-heading"><h1>{{ $state === 'event' ? 'Игра на м. Яхромская' : $mapPreview['name'] }}</h1></div>
        <button type="button" class="app-venue-intro__address" data-venue-map-open><svg aria-hidden="true"><use href="#pin"/></svg><span>{{ $mapPreview['address'] }}</span></button>
    </div>
</main>
@include('ui-studio.cases.venue-map-preview', ['mapShowCurrentVenueLink'=>$state === 'event', 'mapPreview'=>$mapPreview, 'mapAutoOpen'=>true])
