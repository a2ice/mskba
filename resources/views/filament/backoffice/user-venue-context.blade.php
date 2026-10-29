@php
    use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
    use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
    use App\Modules\Venue\Application\Services\VenueMembershipAccess;
    use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
    use App\Modules\Venue\Domain\Models\Venue;
    $access = app(VenueMembershipAccess::class);
    $ids = $editedUser ? $access->contractedVenueIdsFor($editedUser) : [];
    $venues = $ids ? Venue::query()->whereIn('id', $ids)->orderBy('name')->limit(100)->get() : collect();
    $global = $editedUser && app(UserOperationalPermissionChecker::class)
        ->allows($editedUser, Permission::BACKOFFICE_VENUE_MANAGE);
@endphp

<x-filament::section heading="Управление площадками · глобальные" description="Глобальный доступ Filament не равен договорным правам на каждую площадку.">
    <p>{{ $global ? 'Есть глобальное разрешение на управление площадками в Filament.' : 'Глобальное разрешение на управление площадками не предоставлено.' }}</p>
</x-filament::section>

<x-filament::section heading="Управление площадками · по действующим договорам" description="Показываются только площадки с активными контрактами пользователя, включая контракты на конкретные зоны и залы.">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left">
                <th class="p-3">Площадка</th><th class="p-3">Специализация</th><th class="p-3">Базовых разрешений</th><th class="p-3">Изменить</th>
            </tr></thead>
            <tbody>
            @forelse($venues as $venue)
                @php
                    $baseCount = collect(VenuePermissionEnum::cases())
                        ->filter(fn (VenuePermissionEnum $permission) => $access->allows($editedUser, $venue, $permission))
                        ->count();
                @endphp
                <tr class="border-b">
                    <td class="p-3">{{ $venue->name }}</td>
                    <td class="p-3">{{ $access->hasActiveVenueManagementRole($editedUser, $venue) ? 'Владелец / администратор' : 'Договорная роль (в т.ч. зона/зал)' }}</td>
                    <td class="p-3">{{ $baseCount }}</td>
                    <td class="p-3"><span class="text-gray-500" title="Изменения прав договоров выполняются через управление конкретной площадкой">В карточке площадки</span></td>
                </tr>
            @empty
                <tr><td class="p-3" colspan="4">Активных контрактов на площадки нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if(count($ids) > 100)
        <p class="mt-2 text-sm text-gray-500">Показаны первые 100 площадок из {{ count($ids) }}. Для полного списка используй управление договорами.</p>
    @endif
</x-filament::section>