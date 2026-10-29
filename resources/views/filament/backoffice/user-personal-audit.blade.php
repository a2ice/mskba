@php
    use App\Modules\Audit\Domain\Models\AuditLog;
    use App\Modules\Identity\Domain\Models\Profile;
    use App\Modules\Identity\Domain\Models\User;
    $user = $editedUser?->canonical();
    $profileId = $user?->profile?->id;
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
                <th class="p-3">Дата</th><th class="p-3">Действие</th><th class="p-3">Автор</th><th class="p-3">Основание</th>
            </tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr class="border-b">
                    <td class="p-3">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                    <td class="p-3">{{ $log->event }} #{{ $log->id }}</td>
                    <td class="p-3">{{ $log->actor_id ?? 'система' }}</td>
                    <td class="p-3">{{ $log->metadata['reason'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-3">Записей пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-500">Для безопасного отката выбери «Откатить изменение личных данных» в действиях редактора.</p>
</x-filament::section>