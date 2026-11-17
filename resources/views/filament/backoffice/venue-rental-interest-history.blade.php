<div class="space-y-6 text-sm">
    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Данные предзаявки</h3>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><dt class="text-gray-500">Мероприятие</dt><dd>{{ $interest->event?->title ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Организатор</dt><dd>{{ $interest->event?->organizerActor?->user?->username ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Площадка / зал</dt><dd>{{ $interest->venue?->name }} / {{ $interest->court?->name ?? 'Не указан' }}</dd></div>
            <div><dt class="text-gray-500">Статус</dt><dd>{{ $interest->status }} · редакция {{ $interest->revision }}</dd></div>
            <div><dt class="text-gray-500">Интервал</dt><dd>{{ $interest->starts_at?->format('d.m.Y H:i') }} — {{ $interest->ends_at?->format('d.m.Y H:i') }}</dd></div>
            <div><dt class="text-gray-500">Связанная заявка</dt><dd>{{ $interest->booking?->public_id ?? 'Не создана' }}</dd></div>
        </dl>
    </section>

    <section>
        <h3 class="mb-3 font-semibold">История предложений и перехода к бронированию</h3>
        <div class="max-h-60 space-y-2 overflow-y-auto">
            @forelse($interest->proposals->sortBy('created_at') as $proposal)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div>Предложение #{{ $proposal->id }} · {{ $proposal->status }}</div>
                    <div class="text-xs text-gray-500">{{ $proposal->created_at?->format('d.m.Y H:i:s') }} · {{ $proposal->initiator?->user?->username ?? 'Система' }}</div>
                    <div>{{ number_format(($proposal->quoted_amount_minor ?? 0) / 100, 2, ',', ' ') }} {{ $proposal->currency }} · Заявка: {{ $proposal->booking?->public_id ?? 'не создана' }}</div>
                </div>
            @empty
                <p class="text-gray-500">Предложений пока нет.</p>
            @endforelse
        </div>
    </section>

    <section>
        <h3 class="mb-3 font-semibold">Переписка (полная история)</h3>
        <div class="max-h-96 space-y-2 overflow-y-auto">
            @forelse($interest->conversation?->messages?->sortBy('id') ?? [] as $message)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="mb-1 text-xs text-gray-500">{{ $message->authorActor?->user?->username ?? 'Система' }} · {{ $message->created_at?->format('d.m.Y H:i:s') }}</div>
                    <div class="whitespace-pre-wrap break-words">{{ $message->body }}</div>
                    @if($message->attachment_name)
                        <span class="text-xs text-gray-500">Вложение: {{ $message->attachment_name }}</span>
                    @endif
                </div>
            @empty
                <p class="text-gray-500">Сообщений пока нет.</p>
            @endforelse
        </div>
    </section>
    <p class="text-xs text-gray-500">Предзаявка сама по себе не резервирует зал. Предложение аренды проходит стандартный процесс подтверждения организатором.</p>
</div>
