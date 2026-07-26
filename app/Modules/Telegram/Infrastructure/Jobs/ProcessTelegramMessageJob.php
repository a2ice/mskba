<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginStartMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $message */
    public function __construct(public readonly array $message) {}

    public function handle(HandleTelegramBotLoginStartMessage $handler): void
    {
        $handler->handle($this->message);
    }
}
