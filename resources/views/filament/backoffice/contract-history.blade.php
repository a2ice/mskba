@php
    $contract = $this->getRecord();
    $history = \Illuminate\Support\Facades\DB::table('contract_change_logs')
        ->leftJoin('users', 'users.id', '=', 'contract_change_logs.changed_by_user_id')
        ->where('contract_change_logs.contract_id', $contract->id)
        ->orderByDesc('contract_change_logs.id')
        ->select('contract_change_logs.*', 'users.username')
        ->limit(30)->get();
@endphp
<x-filament::section heading="История изменений">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left">
                <th class="p-3">Дата</th><th class="p-3">Действие</th>
                <th class="p-3">Кем изменено</th><th class="p-3">Основание</th>
            </tr></thead>
            <tbody>
            @forelse($history as $entry)
                <tr class="border-b">
                    <td class="p-3 whitespace-nowrap">{{ \Carbon\Carbon::parse($entry->created_at)->format('d.m.Y H:i') }}</td>
                    <td class="p-3">{{ $entry->action === 'revoked' ? 'Отозван' : 'Изменён' }}</td>
                    <td class="p-3">{{ $entry->username ?? 'Система' }}</td>
                    <td class="p-3">{{ $entry->reason }}</td>
                </tr>
            @empty
                <tr><td class="p-3" colspan="4">Изменений пока не было.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
