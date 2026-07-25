<?php

namespace App\Modules\Telegram\Infrastructure\Providers;

use App\Modules\Coordination\Domain\Events\PollActivated;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Telegram\Infrastructure\Listeners\PrepareActivatedPollPublications;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramCoordinationPublicationSync;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramEventPublicationSync;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TelegramServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventChanged::class, QueueTelegramEventPublicationSync::class);
        Event::listen(PollChanged::class, QueueTelegramCoordinationPublicationSync::class);
        Event::listen(PollActivated::class, PrepareActivatedPollPublications::class);
    }
}
