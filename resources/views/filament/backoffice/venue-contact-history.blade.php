<div class="space-y-4">
    <div>
        <p class="font-medium">{{ $contact->value }}</p>
        <p class="text-sm text-gray-500">Добавлен: {{ $contact->created_at?->format('d.m.Y H:i') ?? '—' }}</p>
        <p class="text-sm">Текущее подтверждение: {{ $contact->verified_at?->format('d.m.Y H:i') ?? 'Нет' }}</p>
    </div>
    <div class="space-y-3">
        <h3 class="font-medium">Последние 50 попыток подтверждения</h3>
        @forelse ($attempts as $attempt)
            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <p class="font-medium">{{ $attempt->status->label() }}</p>
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
    <p class="text-sm text-gray-500">История не заменяет текущий статус подтверждения контакта.</p>
</div>
