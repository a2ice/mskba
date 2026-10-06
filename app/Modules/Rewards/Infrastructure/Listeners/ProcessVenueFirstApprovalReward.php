<?php

namespace App\Modules\Rewards\Infrastructure\Listeners;

use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Services\RewardEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessVenueFirstApprovalReward implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(ModerationRequestApproved $event, RewardEngine $engine): void
    {
        $request = ModerationRequest::query()->find($event->request->id);

        if ($request === null || $request->type !== ModerationTypeEnum::VENUE) {
            return;
        }

        $engine->process(
            'venue_first_approval',
            new RewardMechanismContext(
                businessFactType: 'venue_first_approval',
                businessFactKey: 'venue:'.$request->subject_id,
                payload: ['moderation_request_id' => (int) $request->id],
            ),
        );
    }
}
