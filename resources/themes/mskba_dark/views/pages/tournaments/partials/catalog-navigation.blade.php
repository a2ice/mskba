@php
    $persistent = request()->except(['page', 'period']);
    $periods = [
        'all' => ['label' => 'Все турниры', 'icon' => 'ti-trophy'],
        'current' => ['label' => 'Текущие', 'icon' => 'ti-player-play'],
        'upcoming' => ['label' => 'Предстоящие', 'icon' => 'ti-calendar-up'],
        'past' => ['label' => 'Прошедшие', 'icon' => 'ti-history'],
    ];
@endphp

<nav class="default-category-sidebar__nav" aria-label="Навигация турниров">
    @foreach($periods as $value => $item)
        @php
            $query = $persistent;
            if ($value !== 'all') {
                $query['period'] = $value;
            }
        @endphp
        <a @class(['is-active' => $period === $value]) href="{{ route('tournaments.index', $query) }}">
            <span>{{ $item['label'] }}</span>
            <i class="ti {{ $item['icon'] }}" aria-hidden="true"></i>
        </a>
    @endforeach

    @if(auth()->user()?->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED)
        <a href="{{ route('tournaments.create') }}">
            <span>Создать турнир</span>
            <i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @endif
</nav>
