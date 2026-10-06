<?php

namespace App\Modules\Rewards\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Events\UserAccountConfirmed;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Services\RewardEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessUserAccountConfirmedRewards implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly RewardEngine $engine,
    ) {}

    public int $tries = 5;

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(UserAccountConfirmed $event): void
    {
        $user = User::query()->find($event->userId)?->canonical();

        if ($user === null || ! $user->isConfirmed()) {
            return;
        }

        $context = new RewardMechanismContext(
            businessFactType: 'user_confirmation',
            businessFactKey: 'user:'.$user->id,
            payload: ['confirmed_user_id' => (int) $user->id],
        );

        $this->engine->process('referral_user_confirmed', $context);
        $this->engine->process('referral_second_level_user_confirmed', $context);
    }
}
