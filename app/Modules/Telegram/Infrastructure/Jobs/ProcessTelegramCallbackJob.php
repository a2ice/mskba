<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\UseCases\HandleEventParticipationCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramCallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $callback */
    public function __construct(public readonly array $callback) {}

    public function handle(HandleEventParticipationCallback $handler): void
    {
        $handler->handle($this->callback);
    }
}
