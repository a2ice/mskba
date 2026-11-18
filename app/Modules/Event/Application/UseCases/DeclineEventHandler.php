<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;

/**
 * Telegram and legacy decline actions must use the same transactional RSVP
 * writer, rate limit, participant count and timeline as the public web page.
 */
final class DeclineEventHandler
{
    public function __construct(private readonly SetEventParticipationHandler $participation) {}

    public function handle(string $identifier, User $user): Event
    {
        return $this->participation->handle($identifier, $user, EventParticipantStatusEnum::LEFT);
    }
}
