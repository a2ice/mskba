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
    <div class="mskba-contract-history">
        <div class="mskba-contract-table-wrap">
        <table class="mskba-contract-table">
            <thead><tr>
                <th scope="col">Дата</th><th scope="col">Действие</th>
                <th scope="col">Кем изменено</th><th scope="col">Основание</th>
            </tr></thead>
            <tbody>
            @forelse($history as $entry)
                <tr>
                    <td data-label="Дата">{{ \Carbon\Carbon::parse($entry->created_at)->format('d.m.Y H:i') }}</td>
                    <td data-label="Действие">{{ $entry->action === 'revoked' ? 'Отозван' : 'Изменён' }}</td>
                    <td data-label="Кем изменено">{{ $entry->username ?? 'Система' }}</td>
                    <td data-label="Основание">{{ $entry->reason }}</td>
                </tr>
            @empty
                <tr><td class="mskba-contract-table__empty" colspan="4">Изменений пока не было.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</x-filament::section>
