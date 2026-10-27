<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\SystemNotificationTimeline;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SubscriptionDeliveryChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_only_channel_does_not_create_in_app_message(): void
    {
        Queue::fake();
        $user=User::factory()->create();
        $notification=app(CreateUserNotificationHandler::class)->handle(
            new CreateUserNotificationDTO(
                userId:$user->id, type:UserNotificationTypeEnum::REMINDER,
                title:'Новая игра', body:'Площадка обновилась',
                payload:['source'=>'favorites.venue.upcoming','delivery_channels'=>['telegram']],
            )
        );

        $this->assertSame(UserNotificationStatusEnum::READ, $notification->status);
        $this->assertCount(0, app(SystemNotificationTimeline::class)->page($user)['entries']);
    }

    public function test_in_app_channel_keeps_regular_new_message(): void
    {
        Queue::fake();
        $user=User::factory()->create();
        $notification=app(CreateUserNotificationHandler::class)->handle(
            new CreateUserNotificationDTO(
                userId:$user->id, type:UserNotificationTypeEnum::REMINDER,
                title:'Новая игра', body:'Площадка обновилась',
                payload:['source'=>'favorites.venue.upcoming','delivery_channels'=>['in_app']],
            )
        );

        $this->assertSame(UserNotificationStatusEnum::NEW, $notification->status);
        $this->assertCount(1, app(SystemNotificationTimeline::class)->page($user)['entries']);
    }
}
