<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Arr;

final class VenueContactHistory
{
    public const FIELDS = [
        'type' => 'Тип',
        'value' => 'Контакт',
        'label' => 'Назначение',
        'comment' => 'Внутренний комментарий',
        'is_primary' => 'Основной',
        'is_public' => 'Публичный',
        'verified_at' => 'Подтверждён',
    ];

    public function resolve(?User $actor, Venue $venue, int $contactId): array
    {
        $actor = $actor?->canonical()->fresh();
        $venue = Venue::query()->findOrFail($venue->id);
        abort_unless(app(AdminManageVenueContactsHandler::class)->canManage($actor, $venue), 403);
        $contact = $venue->contacts()->withTrashed()->findOrFail($contactId);
        $attempts = $contact->verifications()->latest('id')->limit(50)
            ->get(['id', 'sent_to', 'status', 'attempts_count', 'created_at', 'verified_at', 'expires_at']);
        $changes = AuditLog::query()->where('auditable_type', Contact::class)
            ->where('auditable_id', $contact->id)->whereIn('event', ['created', 'updated', 'deleted'])
            ->latest('id')->limit(50)
            ->get(['id', 'event', 'actor_id', 'created_at', 'old_values', 'new_values'])
            ->map(fn (AuditLog $log): array => [
                'event' => $log->event,
                'actor_id' => $log->actor_id,
                'created_at' => $log->created_at,
                'old' => Arr::only($log->old_values ?? [], array_keys(self::FIELDS)),
                'new' => Arr::only($log->new_values ?? [], array_keys(self::FIELDS)),
            ]);

        return ['contact' => $contact, 'attempts' => $attempts, 'changes' => $changes, 'fields' => self::FIELDS];
    }
}
