<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Models\Actor;
use InvalidArgumentException;

final class EventResponsibilityPermissionManager
{
    public function __construct(private readonly EventManagementAccess $access,
        private readonly EventContractResponsibilityService $contracts,
    ) {}

    /** @param list<string> $values */
    public function replace(Event $event, EventParticipant $participant, Actor $actor, array $values): void
    {
        $permissions = $this->normalize($values);
        $effective = collect($this->access->effectivePermissions($event, $actor))
            ->map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value);

        $forbidden = collect($permissions)->reject(fn (EventResponsibilityPermissionEnum $permission): bool => $effective->contains($permission->value));
        if ($forbidden->isNotEmpty()) {
            throw new InvalidArgumentException('Нельзя выдать права, которыми вы не обладаете.');
        }

        $this->contracts->assign($event, $participant, $actor,
            array_map(static fn (EventResponsibilityPermissionEnum $right): string => $right->value, $permissions));
    }

    /** @param list<string> $values
     * @return list<EventResponsibilityPermissionEnum>
     */
    private function normalize(array $values): array
    {
        $permissions = collect($values)
            ->unique()
            ->map(function (string $value): EventResponsibilityPermissionEnum {
                $permission = EventResponsibilityPermissionEnum::tryFrom($value);
                if ($permission === null) {
                    throw new InvalidArgumentException('Передано неизвестное право ответственного.');
                }

                return $permission;
            });

        if ($permissions->contains(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS)
            && ! $permissions->contains(EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE)) {
            throw new InvalidArgumentException('Полная статистика требует права вести счёт.');
        }

        return $permissions->values()->all();
    }
}
