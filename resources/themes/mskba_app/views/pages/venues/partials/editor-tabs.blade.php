@php
    $activeTab = $activeTab ?? 'general';
    $tabs = [
        'general' => 'Основное',
        'address' => 'Адрес и метро',
        'facilities' => 'Удобства',
        'courts' => 'Залы и корты',
        'schedule' => 'Расписание',
        'photos' => 'Фотографии',
        'contracts' => 'Контракты',
        'rental' => 'Аренда',
    ];
    $canRentalTab = ($editor['rental'] ?? false) || ($editor['offers'] ?? false) || ($editor['memberships'] ?? false);
@endphp
<nav class="app-venue-editor__tabs" aria-label="Разделы редактирования площадки">
    @foreach($tabs as $key => $label)
        @php($allowed = $key === 'rental' ? $canRentalTab : (bool) ($editor[$key] ?? false))
        @if($allowed)
            <a href="{{ route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => $key]) }}"
                @class(['is-active' => $activeTab === $key])
                @if($activeTab === $key) aria-current="page" @endif>{{ $label }}</a>
        @else
            <span class="app-venue-editor__tab-disabled" aria-disabled="true" title="Нет разрешения для этого раздела">{{ $label }}</span>
        @endif
    @endforeach
</nav>
