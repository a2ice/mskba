@php
    use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
    use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
    use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
    use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
    use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
    use App\Modules\Venue\Application\Services\VenueCommercialAccess;
    use App\Modules\Venue\Application\Services\VenueMembershipAccess;
    use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
    use App\Modules\Venue\Domain\Models\Venue;
    use App\Modules\Venue\Domain\Models\VenueCourt;

    $access = app(VenueMembershipAccess::class);
    $memberships = $editedUser ? $access->activeVenueMembershipsFor($editedUser) : collect();
    $courtIds = $memberships->filter(fn ($membership) => $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT)
        ->pluck('scope_id')->all();
    $courts = VenueCourt::query()->whereIn('id', $courtIds)->get()->keyBy('id');
    $venueIds = $memberships->filter(fn ($membership) => $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE)
        ->pluck('scope_id')->concat($courts->pluck('venue_id'))->unique()->all();
    $venues = Venue::query()->whereIn('id', $venueIds)->get()->keyBy('id');
    $global = $editedUser && app(UserOperationalPermissionChecker::class)
        ->allows($editedUser, Permission::BACKOFFICE_VENUE_MANAGE);
    $actor = auth('web')->user();
    $canEditUser = $editedUser && BackofficeUserResource::canEdit($editedUser);
    $commercialAccess = app(VenueCommercialAccess::class);
@endphp

<x-filament::section heading="Управление площадками · глобальные" description="Глобальный доступ Filament не равен договорным правам на конкретную площадку или зал.">
    <p>{{ $global ? 'Глобальное разрешение Filament предоставлено.' : 'Глобальное разрешение Filament не предоставлено.' }}</p>
    <p class="mt-2 text-sm text-gray-500">Изменяется отдельно в системных правах пользователя. Договорные роли этим флагом не заменяются.</p>
</x-filament::section>

<x-filament::section heading="Управление площадками · активные договоры" description="Права площадки и отдельного зала показаны разными строками. Истёкшие и отозванные договоры не включены.">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left">
                <th class="p-3">Область</th><th class="p-3">Специализация</th><th class="p-3">Базовых разрешений</th><th class="p-3">Изменить</th>
            </tr></thead>
            <tbody>
            @forelse($memberships as $membership)
                @php
                    $isCourt = $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT;
                    $court = $isCourt ? $courts->get($membership->scope_id) : null;
                    $venue = $isCourt ? $venues->get($court?->venue_id) : $venues->get($membership->scope_id);
                    $role = VenueMembershipAccessLevelEnum::tryFrom((string) $membership->access_level);
                    $permissionCount = $membership->contract?->permissions?->count() ?? 0;
                    $mayManageContract = $actor && $venue && $canEditUser && $role?->isCommercialRole()
                        && $role !== VenueMembershipAccessLevelEnum::OWNER
                        && ($court
                            ? $commercialAccess->allowsForCourt($actor, $court, VenuePermissionEnum::MANAGE_MEMBERSHIPS)
                            : $commercialAccess->allows($actor, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS));
                @endphp
                @if($venue)
                    <tr class="border-b">
                        <td class="p-3">
                            {{ $venue->name }}
                            <span class="block text-xs text-gray-500">
                                {{ $isCourt ? 'Зал / зона: '.($court?->name ?? 'удалён') : 'Вся площадка' }}
                                · договор #{{ $membership->contract_id }}
                            </span>
                        </td>
                        <td class="p-3">{{ $role?->label() ?? $membership->access_level }}</td>
                        <td class="p-3">{{ $permissionCount }}</td>
                        <td class="p-3">
                            @if($mayManageContract)
                                <button type="button" class="text-orange-600 underline"
                                    wire:click="mountAction('editVenueContract', { membership_id: {{ $membership->id }} })">Изменить</button>
                            @else
                                <span class="text-gray-500" title="Изменение договора недоступно для текущей роли">Нет доступа</span>
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td class="p-3" colspan="4">Активных договоров на площадки и залы нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
