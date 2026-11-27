<div class="space-y-3">
    <p>Статус: {{ $status }}</p>
    <p>Ставка: {{ $rate }}</p>
    <p class="whitespace-pre-wrap break-words">{{ $agreement->terms }}</p>
    <p>Полномочия: {{ implode(', ', $permissions) ?: 'Нет дополнительных полномочий' }}</p>
    @if($agreement->accepted_at)<p>Акцепт: {{ $agreement->accepted_at->format('d.m.Y H:i') }}</p>@endif
    @if($agreement->reviewed_at)
        <p>Проверено: {{ $agreement->reviewed_at->format('d.m.Y H:i') }}</p>
        <p class="break-words">Основание: {{ $agreement->evidence_reference }}</p>
        <p class="whitespace-pre-wrap break-words">{{ $agreement->review_reason }}</p>
    @endif
    @if($agreement->contract_id)<p>Контракт № {{ $agreement->contract_id }}</p>@endif
    @if($agreement->closed_at)<p>Закрыт: {{ $agreement->closed_at->format('d.m.Y H:i') }}</p><p class="break-words">{{ $agreement->closure_reason }}</p>@endif
</div>
