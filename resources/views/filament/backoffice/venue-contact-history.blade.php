<div class="space-y-4">
    <div>
        <p class="font-medium">{{ $contact->value }}</p>
        <p class="text-sm text-gray-500">Добавлен: {{ $contact->created_at?->format('d.m.Y H:i') ?? '—' }}</p>
        @if ($contact->trashed())
            <p class="text-sm">Архив: удалён {{ $contact->deleted_at?->format('d.m.Y H:i') }}</p>
        @endif
        <p class="text-sm">Текущее подтверждение: {{ $contact->verified_at?->format('d.m.Y H:i') ?? 'Нет' }}</p>
    </div>
    <div class="space-y-3">
        <h3 class="font-medium">Последние 50 попыток подтверждения</h3>
        @forelse ($attempts as $attempt)
            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <p class="font-medium">{{ $attempt->effectiveStatus()->label() }}</p>
                <p>{{ $attempt->sent_to }}</p>
                <p>Отправлено: {{ $attempt->created_at?->format('d.m.Y H:i') ?? '—' }}</p>
                <p>Попыток ввода: {{ $attempt->attempts_count }}</p>
                @if ($attempt->verified_at)
                    <p>Подтверждено: {{ $attempt->verified_at->format('d.m.Y H:i') }}</p>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Попыток подтверждения пока нет.</p>
        @endforelse
    </div>
    <div class="space-y-3">
        <h3 class="font-medium">Последние 50 изменений контакта</h3>
        @forelse ($changes as $change)
            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <p class="font-medium">{{ match ($change['event']) { 'created' => 'Создан', 'updated' => 'Изменён', 'deleted' => 'Удалён', default => 'Изменение' } }}</p>
                <p>{{ $change['created_at']?->format('d.m.Y H:i') ?? '—' }} · Исполнитель (ID): {{ $change['actor_id'] ?? '—' }}</p>
                @foreach ($fields as $key => $label)
                    @if (array_key_exists($key, $change['old']) || array_key_exists($key, $change['new']))
                        <p class="break-words">{{ $label }}:
                            {{ is_bool($change['old'][$key] ?? null) ? ($change['old'][$key] ? 'Да' : 'Нет') : ($change['old'][$key] ?? '—') }}
                            →
                            {{ is_bool($change['new'][$key] ?? null) ? ($change['new'][$key] ? 'Да' : 'Нет') : ($change['new'][$key] ?? '—') }}
                        </p>
                    @endif
                @endforeach
            </div>
        @empty
            <p class="text-sm text-gray-500">Записей аудита нет.</p>
        @endforelse
    </div>
    <p class="text-sm text-gray-500">История не заменяет текущий статус подтверждения контакта.</p>
</div>
