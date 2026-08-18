<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;

final class EventIdentityParticipationService
{
    /** @return Collection<int, EventParticipant> */
    public function participants(Event $event, User $user): Collection
    {
        $canonical = $user->canonical();

        return $event->participants()
            ->whereIn('user_id', $canonical->identityIds())
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$canonical->id])
            ->orderBy('id')
            ->get();
    }

    public function effectiveParticipant(Event $event, User $user): ?EventParticipant
    {
        $canonical = $user->canonical();
        $participants = $this->participants($event, $canonical);

        return $participants->first(fn (EventParticipant $participant): bool => $participant->role === EventParticipantRoleEnum::ORGANIZER)
            ?? $participants->first(fn (EventParticipant $participant): bool => $participant->status === EventParticipantStatusEnum::CONFIRMED
                && $participant->confirmation_version === $event->participation_confirmation_version)
            ?? $participants->first(fn (EventParticipant $participant): bool => $participant->status === EventParticipantStatusEnum::CONFIRMED)
            ?? $participants->first(fn (EventParticipant $participant): bool => (int) $participant->user_id === (int) $canonical->id)
            ?? $participants->first();
    }

    public function confirmedIdentityCount(Event $event): int
    {
        return count($this->confirmedCanonicalIds($event));
    }

    /**
     * Returns every physical user id belonging to an identity that is already
     * confirmed for the current participation version. This is used to keep
     * aliases out of participant candidate search results.
     *
     * @return list<int>
     */
    public function confirmedIdentityIds(Event $event): array
    {
        $identityIds = [];

        foreach ($this->confirmedUsers($event) as $user) {
            foreach ($user->canonical()->identityIds() as $identityId) {
                $identityIds[$identityId] = true;
            }
        }

        return array_map('intval', array_keys($identityIds));
    }

    /** @return list<int> */
    private function confirmedCanonicalIds(Event $event): array
    {
        $canonicalIds = [];

        foreach ($this->confirmedUsers($event) as $user) {
            $canonicalIds[(int) $user->canonical()->id] = true;
        }

        return array_map('intval', array_keys($canonicalIds));
    }

    /** @return Collection<int, User> */
    private function confirmedUsers(Event $event): Collection
    {
        $userIds = $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get();
    }
}
