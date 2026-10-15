@php
    $ticket = $getRecord();
    $history = $ticket->thread
        ? app(\App\Modules\Notification\Application\Services\SupportTicketTimeline::class)
            ->page($ticket->thread, limit: 50)
        : ['entries' => collect(), 'next_before' => null];
@endphp
<div class="space-y-3">
    @forelse ($history['entries'] as $entry)
        <div class="rounded-lg border p-3">
            <div class="text-sm font-semibold">
                {{ $entry['kind'] === 'system' ? 'Система MSKBA' : ($entry['sender']?->username ?? 'Участник') }}
                <span class="text-xs font-normal opacity-60">{{ $entry['at']?->format('d.m.Y H:i') }}</span>
            </div>
            @if (! empty($entry['source_path']))
                <p class="text-xs">Страница обращения: {{ $entry['source_path'] }}</p>
            @endif
            <p class="mt-2 whitespace-pre-wrap text-sm">{{ $entry['text'] }}</p>
        </div>
    @empty
        <p>Пока нет сообщений.</p>
    @endforelse
    @if ($history['next_before'])
        <p class="text-xs opacity-60">Показаны последние 50 событий. Полная история доступна в разделе сообщений портала.</p>
    @endif
</div>
