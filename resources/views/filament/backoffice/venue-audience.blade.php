@php
    $venue = $this->getRecord();
    $summary = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
        ->forManagement($venue, auth('web')->user(), true);
@endphp
<x-filament::section heading="Интересуются" description="Прямой и подходящий по условиям интерес. Закрытые интересы учитываются только в агрегатах.">
    <p>Всего: {{ $summary['total'] }} · Прямой интерес: {{ $summary['direct_count'] }} · По условиям: {{ $summary['matched_count'] }} · Без открытого профиля интереса: {{ $summary['anonymous_count'] }}</p>
    <div class="mskba-contract-table-wrap">
        <table class="mskba-contract-table">
            <thead><tr><th scope="col">Пользователь</th><th scope="col">Роли</th><th scope="col">Причина</th><th scope="col">Активность</th></tr></thead>
            <tbody>
            @forelse(array_slice($summary['people'], 0, 20) as $person)
                <tr><td data-label="Пользователь"><a href="{{ $person['url'] }}">{{ $person['name'] }}</a></td>
                    <td data-label="Роли">{{ collect($person['roles'])->pluck('label')->join(', ') ?: '—' }}</td>
                    <td data-label="Причина">{{ $person['reason'] }}</td>
                    <td data-label="Активность">{{ $person['games'] === null ? '—' : 'Игр: '.$person['games'] }} · {{ $person['organized'] === null ? '—' : 'Организовано: '.$person['organized'] }}</td></tr>
            @empty<tr><td colspan="4">Нет пользователей с открытым интересом.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <x-filament::button tag="a" :href="route('account.venue-audience.backoffice', $venue->id)">Все интересующиеся и фильтры</x-filament::button>
</x-filament::section>
