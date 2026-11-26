<div class="space-y-4">
    <p><strong>{{ $invitation->title }}</strong> · {{ $invitation->targetName() }}</p>
    <p>{{ $invitation->description }}</p>
    <p>Получатель: {{ $invitation->contact_value }}</p>
    <p>Приглашение: {{ $invitation->invitationStatusLabel() }} · Системный статус: {{ $invitation->systemStatusLabel() }} · Процедура: {{ $invitation->statusLabel() }} · {{ $invitation->progressLabel() }}</p>
    <p>Создал: {{ $invitation->issuer?->username }} · Принял: {{ $invitation->acceptedUser?->username ?? '—' }}</p>
    <p>Отправлено: {{ $invitation->sent_at?->format('d.m.Y H:i') ?? '—' }} · Первое техническое открытие: {{ $invitation->viewed_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <p>Первое проявление интереса: {{ $invitation->interested_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <p>Срок действия: {{ $invitation->expires_at?->format('d.m.Y H:i') ?? '—' }} · Аннулировано: {{ $invitation->revoked_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <p>Принято / подтверждение контакта: {{ $invitation->contact_verified_at?->format('d.m.Y H:i') ?? '—' }} · Завершено: {{ $invitation->completed_at?->format('d.m.Y H:i') ?? '—' }}</p>
    <p class="text-sm text-gray-500 dark:text-gray-400">Открытие ссылки может быть выполнено почтовым сканером и не подтверждает интерес получателя.</p>
    <hr>
    <h3 class="font-semibold">События</h3>
    <ol class="space-y-2">
        @foreach($invitation->events as $event)
            <li><time>{{ $event->created_at?->format('d.m.Y H:i:s') }}</time> · {{ match($event->event) {
                'created' => 'Создано',
                'sent' => 'Отправлено',
                'viewed' => 'Ссылка открыта',
                'interested' => 'Заинтересован',
                'link_renewed' => 'Срок продлён / ссылка обновлена',
                'revoked' => 'Аннулировано',
                'restored' => 'Восстановлено',
                'registered' => 'Аккаунт создан',
                'contact_verified' => 'Контакт подтверждён',
                'awaiting_account' => 'Ожидается подтверждение аккаунта',
                'awaiting_authorization' => 'Ожидается согласование полномочий: права отправителя изменились',
                'authorization_reviewed' => 'Завершение согласовано',
                'awaiting_ownership' => 'Ожидается проверка владения',
                'completed' => 'Процедура завершена',
                default => $event->event,
            } }}
                @if(in_array($event->event, ['link_renewed', 'restored'], true))
                    · {{ data_get($event->details, 'previous_expires_at', '—') }} → {{ data_get($event->details, 'expires_at', '—') }}
                @endif
                @if(in_array($event->event, ['restored', 'authorization_reviewed'], true))
                    · Причина: {{ data_get($event->details, 'reason', '—') }}
                @endif
                @if(data_get($event->details, 'reviewer_id'))
                    · ID согласовавшего сотрудника: {{ data_get($event->details, 'reviewer_id') }}
                @endif
                @if(data_get($event->details, 'issuer_id'))
                    · ID сотрудника: {{ data_get($event->details, 'issuer_id') }}
                @endif
            </li>
        @endforeach
    </ol>
</div>
