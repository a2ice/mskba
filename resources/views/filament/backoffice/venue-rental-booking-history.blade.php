<div class="space-y-6 text-sm">
    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Данные заявки</h3>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><dt class="text-gray-500">Площадка / зал</dt><dd>{{ $booking->venue?->name }} / {{ $booking->court?->name ?? 'Не указан' }}</dd></div>
            <div><dt class="text-gray-500">Заявитель</dt><dd>{{ $booking->requester?->username ?? 'Пользователь #'.$booking->requester_user_id }}</dd></div>
            <div><dt class="text-gray-500">Состояние</dt><dd>{{ $booking->status?->label() ?? '—' }} · версия {{ $booking->optimistic_version }}</dd></div>
            <div><dt class="text-gray-500">Интервал</dt><dd>{{ $booking->starts_at?->format('d.m.Y H:i') }} — {{ $booking->ends_at?->format('d.m.Y H:i') }}</dd></div>
            @if($booking->venueInterest)
                <div><dt class="text-gray-500">Создана из предзаявки</dt><dd>#{{ $booking->venueInterest->id }} · {{ $booking->venueInterest->event?->title }}</dd></div>
            @endif
            @if($booking->pre_request_id)
                <div><dt class="text-gray-500">Самостоятельная предзаявка</dt><dd>
                    <a href="{{ route('account.venue-pre-requests.show', $booking->preRequest) }}">#{{ $booking->pre_request_id }}</a>
                    · представитель Actor #{{ $booking->created_by_representative_id }}
                </dd></div>
            @endif
            <div><dt class="text-gray-500">Создана</dt><dd>{{ $booking->created_at?->format('d.m.Y H:i:s') }}</dd></div>
        </dl>
    </section>

    <section>
        <h3 class="mb-3 font-semibold">История изменения статуса</h3>
        <div class="max-h-72 space-y-2 overflow-y-auto">
            @forelse($booking->transitions as $transition)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="font-medium">{{ $transition->from_status?->label() ?? 'Создание' }} → {{ $transition->to_status?->label() ?? '—' }}</div>
                    <div class="text-gray-500">{{ $transition->created_at?->format('d.m.Y H:i:s') }} · {{ $transition->actor?->user?->username ?? 'Система' }} · версия {{ $transition->booking_version }}</div>
                    @if($transition->reason)
                        <div class="mt-1 whitespace-pre-wrap">{{ $transition->reason }}</div>
                    @endif
                </div>
            @empty
                <p class="text-gray-500">Переходов статуса пока нет.</p>
            @endforelse
        </div>
    </section>

    <section>
        <h3 class="mb-3 font-semibold">Переписка (полная история)</h3>
        <div class="max-h-96 space-y-2 overflow-y-auto">
            @forelse(($booking->preRequestConversation ?? $booking->conversation)?->messages?->sortBy('id') ?? [] as $message)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="mb-1 text-xs text-gray-500">{{ $message->authorActor?->user?->username ?? 'Система' }} · {{ $message->created_at?->format('d.m.Y H:i:s') }}</div>
                    <div class="whitespace-pre-wrap break-words">{{ $message->body }}</div>
                    @if($message->attachment_path)
                        <a class="text-primary-600 underline" href="{{ route('account.venue-bookings.conversation.attachment', ['venueBooking' => $booking, 'message' => $message]) }}" target="_blank" rel="noopener noreferrer">Вложение: {{ $message->attachment_name }}</a>
                    @endif
                </div>
            @empty
                <p class="text-gray-500">Сообщений пока нет.</p>
            @endforelse
        </div>
    </section>
    <p class="text-xs text-gray-500">Просмотр переписки сотрудником фиксируется в журнале аудита. Все действия выполняются от его учётной записи.</p>
</div>
