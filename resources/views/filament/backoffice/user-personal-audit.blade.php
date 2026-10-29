@php
    use App\Modules\Audit\Domain\Models\AuditLog;
    use App\Modules\Identity\Domain\Models\Profile;
    use App\Modules\Identity\Domain\Models\User;
    $user = $editedUser?->canonical();
    $profileId = $user?->profile?->id;
    // Show before/after only for approved administrative revision event shapes.
    // Generic Auditable events may include fields that are not safe to reveal here.
    $revisableEvents = [
        'admin_personal_profile_changed', 'admin_personal_profile_reverted',
        'admin_player_profile_changed', 'admin_player_profile_reverted',
        'admin_participation_role_changed', 'admin_participation_role_reverted',
        'admin_user_permissions_changed', 'admin_user_permissions_reverted',
        'admin_venue_membership_changed', 'admin_venue_membership_reverted',
        'admin_staff_specializations_changed', 'admin_staff_specializations_reverted',
        'admin_system_role_changed', 'admin_system_role_reverted',
    ];
    $logs = $user ? AuditLog::query()
        ->where(fn ($query) => $query
            ->where(fn ($q) => $q->where('auditable_type', User::class)->where('auditable_id', $user->id))
            ->orWhere(fn ($q) => $q->where('auditable_type', Profile::class)->where('auditable_id', $profileId ?? 0)))
        ->latest('id')->limit(50)->get() : collect();
@endphp

<x-filament::section heading="История изменений" description="Журнал append-only. Откат персональных данных создаёт новую запись с проверкой конфликтов. Пароли, токены и согласия сюда не выводятся.">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left">
                <th class="p-3">Дата</th><th class="p-3">Действие</th><th class="p-3">Автор</th><th class="p-3">Основание</th><th class="p-3">До / после</th>
            </tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr class="border-b">
                    <td class="p-3">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                    <td class="p-3">{{ $log->event }} #{{ $log->id }}</td>
                    <td class="p-3">{{ $log->actor_id ?? 'система' }}</td>
                    <td class="p-3">{{ $log->metadata['reason'] ?? '—' }}</td>
                    <td class="p-3">
                        @if(in_array($log->event, $revisableEvents, true))
                            <details>
                                <summary class="cursor-pointer text-orange-600">Сравнить значения</summary>
                                <div class="mt-2 space-y-2 break-all text-xs">
                                    <div><strong>До:</strong> {{ json_encode($log->old_values ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
                                    <div><strong>После:</strong> {{ json_encode($log->new_values ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
                                </div>
                            </details>
                        @else
                            <span class="text-gray-500">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-3">Записей пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-500">Для безопасного отката выбери «Откатить изменение личных данных» в действиях редактора.</p>
</x-filament::section>