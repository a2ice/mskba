<div class="space-y-4">
    <p><strong>{{ $invitation->title }}</strong> · {{ $invitation->targetName() }}</p>
    <p>{{ $invitation->description }}</p>
    <p>Получатель: {{ $invitation->contact_value }}</p>
    <p>Статус: {{ $invitation->statusLabel() }} · {{ $invitation->progressLabel() }}</p>
    <p>Создал: {{ $invitation->issuer?->username }} · Принял: {{ $invitation->acceptedUser?->username ?? '—' }}</p>
    <p>Отправлено: {{ $invitation->sent_at?->format('d.m.Y H:i') ?? '—' }} · Первое открытие: {{ $invitation->viewed_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <p>Подтверждение контакта: {{ $invitation->contact_verified_at?->format('d.m.Y H:i') ?? '—' }} · Завершено: {{ $invitation->completed_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <hr>
    <h3 class="font-semibold">События</h3>
    <ol class="space-y-2">
        @foreach($invitation->events as $event)
            <li><time>{{ $event->created_at?->format('d.m.Y H:i:s') }}</time> · {{ $event->event }}</li>
        @endforeach
    </ol>
</div>
