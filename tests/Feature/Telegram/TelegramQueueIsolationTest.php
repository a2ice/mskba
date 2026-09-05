<?php

namespace Tests\Feature\Telegram;

use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramMessageJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionCountJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionJob;
use App\Modules\Telegram\Infrastructure\Jobs\SendUserNotificationToTelegramJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramContentPublicationJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramCoordinationPublicationJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramEventPublicationJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramVenueRentalPublicationJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_telegram_jobs_use_the_dedicated_fast_queue(): void
    {
        $message = new ProcessTelegramMessageJob([]);
        $callback = new ProcessTelegramCallbackJob([]);

        $this->assertSame('sync', $message->connection);
        $this->assertSame('telegram-inbound', $message->queue);
        $this->assertSame('sync', $callback->connection);
        $this->assertSame('telegram-inbound', $callback->queue);
    }

    public function test_all_other_telegram_jobs_use_the_dedicated_background_queue(): void
    {
        $jobs = [
            new ProcessTelegramReactionJob([]),
            new ProcessTelegramReactionCountJob([]),
            new SyncTelegramEventPublicationJob(42),
            new SyncTelegramContentPublicationJob(42),
            new SyncTelegramCoordinationPublicationJob(42),
            new SyncTelegramVenueRentalPublicationJob(42),
            new SyncTelegramProfileAvatarJob(42),
            new SendUserNotificationToTelegramJob(42),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('sync', $job->connection, $job::class);
            $this->assertSame('telegram-background', $job->queue, $job::class);
        }
    }

    public function test_event_publication_sync_has_independent_unique_keys_for_changes_and_start_refresh(): void
    {
        $change = new SyncTelegramEventPublicationJob(42, 'change');
        $sameChange = new SyncTelegramEventPublicationJob(42, 'change');
        $start = new SyncTelegramEventPublicationJob(42, 'start:1900000000');

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $change);
        $this->assertSame($change->uniqueId(), $sameChange->uniqueId());
        $this->assertNotSame($change->uniqueId(), $start->uniqueId());
        $this->assertSame('42:change', $change->uniqueId());
        $this->assertSame('42:start:1900000000', $start->uniqueId());
    }

    public function test_event_change_routes_immediate_and_start_refreshes_to_background_queue(): void
    {
        Queue::fake();
        $event = Event::factory()->create([
            'starts_at' => now()->addHours(2),
        ]);

        event(new EventChanged($event->id));

        Queue::assertPushedOn(
            'telegram-background',
            SyncTelegramEventPublicationJob::class,
            fn (SyncTelegramEventPublicationJob $job): bool => $job->eventId === $event->id
                && $job->syncKey === 'change'
                && $job->delay === null,
        );
        Queue::assertPushedOn(
            'telegram-background',
            SyncTelegramEventPublicationJob::class,
            fn (SyncTelegramEventPublicationJob $job): bool => $job->eventId === $event->id
                && $job->syncKey === 'start:'.$event->starts_at->getTimestamp()
                && $job->delay !== null,
        );
    }
}
