<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RespondEventResponsibilityHandler
{
    public function __construct(private readonly EventManagementAccess $access,
        private readonly EntityContractManager $contracts) {}

    public function handle(
        string $identifier,
        int $participantId,
        User $user,
        EventResponsibilityStatusEnum $decision,
    ): Event {
        if (! in_array($decision, [EventResponsibilityStatusEnum::ACCEPTED, EventResponsibilityStatusEnum::DECLINED], true)) {
            throw new InvalidArgumentException('Недопустимый ответ на назначение.');
        }

        $user = $user->canonical();

        $event = DB::transaction(function () use ($identifier, $participantId, $user, $decision): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertOwnsManagementScope($event);

            if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
                || $event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Срок ответа на назначение истёк.');
            }

            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();

            if (! in_array((int) $participant->user_id, $user->identityIds(), true)
                || $participant->responsibility_status !== EventResponsibilityStatusEnum::PENDING) {
                throw new InvalidArgumentException('Это приглашение недоступно.');
            }

            if ($participant->status !== EventParticipantStatusEnum::CONFIRMED
                || $participant->confirmation_version !== $event->participation_confirmation_version) {
                throw new InvalidArgumentException('Сначала подтвердите участие в мероприятии.');
            }

            $participant->forceFill([
                'responsibility_status' => $decision,
                'responsibility_responded_at' => now(),
            ])->save();
            $membership = $participant->responsibilityContractMembership;
            if ($membership === null) {
                throw new InvalidArgumentException('Для назначения не найден контракт.');
            }
            $this->contracts->respond($membership, $user, $decision === EventResponsibilityStatusEnum::ACCEPTED);

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
