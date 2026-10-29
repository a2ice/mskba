@php
    use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
    use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
    use App\Modules\Identity\Domain\Models\SystemRolePermissionProfile;
    use App\Filament\Backoffice\Resources\SystemRoleProfiles\SystemRoleProfileResource;
    $roles = [Role::SUPERADMIN, Role::ADMIN, Role::MANAGER, Role::SYSTEM, Role::USER];
@endphp

<x-filament::section heading="Системные роли" description="Роль пользователя и базовые права каждой системной роли. Изменение шаблона прав влияет на всех пользователей этой роли.">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left">
                <th class="p-3">Роль</th><th class="p-3">Базовых разрешений</th><th class="p-3">Изменить</th>
            </tr></thead>
            <tbody>
            @foreach($roles as $role)
                @php
                    $profile = SystemRolePermissionProfile::query()->find($role->value);
                    $canEditProfile = $profile && SystemRoleProfileResource::canEdit($profile);
                @endphp
                <tr class="border-b">
                    <td class="p-3">
                        {{ $role->label() }}
                        @if($editedUser?->system_role === $role) <span class="text-orange-600">(текущая)</span> @endif
                    </td>
                    <td class="p-3">{{ count(app(StaffPermissionProfiles::class)->roleKeys($role)) }}</td>
                    <td class="p-3">
                        @if($canEditProfile)
                            <a class="text-orange-600 underline" href="{{ SystemRoleProfileResource::getUrl('edit', ['record' => $profile]) }}">Изменить</a>
                        @else
                            <span class="text-gray-500" title="Нет права изменять этот шаблон роли">Нет доступа</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-500">Индивидуальные разрешения пользователя настраиваются ниже. Служебную роль System нельзя назначать через этот редактор.</p>
</x-filament::section>