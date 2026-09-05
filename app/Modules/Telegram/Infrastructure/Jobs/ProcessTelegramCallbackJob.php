<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\UseCases\HandleCoordinationVoteCallback;
use App\Modules\Telegram\Application\UseCases\HandleEventParticipationCallback;
use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginCallback;
use App\Modules\Telegram\Application\UseCases\HandleVenueRentalCoordinationCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramCallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $callback */
    public function __construct(public readonly array $callback, public readonly ?int $updateId = null)
    {
        $this->onConnection((string) config('telegram.queue_connection', 'redis'));
        $this->onQueue((string) config('telegram.queues.inbound', 'telegram-inbound'));
    }

    public function handle(
        HandleEventParticipationCallback $eventHandler,
        HandleCoordinationVoteCallback $coordinationHandler,
        HandleTelegramBotLoginCallback $loginHandler,
        HandleVenueRentalCoordinationCallback $venueRentalHandler,
    ): void {
        $data = data_get($this->callback, 'data');

        if (is_string($data) && str_starts_with($data, 'auth:login:')) {
            $loginHandler->handle($this->callback);

            return;
        }

        if (is_string($data) && str_starts_with($data, 'coord:')) {
            $coordinationHandler->handle($this->callback);

            return;
        }

        if (is_string($data) && str_starts_with($data, 'rentalcoord:')) {
            $venueRentalHandler->handle($this->callback, $this->updateId);

            return;
        }

        $eventHandler->handle($this->callback);
    }
}
