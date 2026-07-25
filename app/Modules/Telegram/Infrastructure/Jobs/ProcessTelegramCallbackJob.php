<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\UseCases\HandleCoordinationVoteCallback;
use App\Modules\Telegram\Application\UseCases\HandleEventParticipationCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramCallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $callback */
    public function __construct(public readonly array $callback) {}

    public function handle(
        HandleEventParticipationCallback $eventHandler,
        HandleCoordinationVoteCallback $coordinationHandler,
    ): void {
        $data = data_get($this->callback, 'data');

        if (is_string($data) && str_starts_with($data, 'coord:')) {
            $coordinationHandler->handle($this->callback);

            return;
        }

        $eventHandler->handle($this->callback);
    }
}
