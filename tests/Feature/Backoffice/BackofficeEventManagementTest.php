<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Events\EventBackofficeEditor;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BackofficeEventManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        $user = User::factory()->create([
            'system_role' => $role, 'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_can_create_and_edit_event_with_booking_and_organizer(): void
    {
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(EventResource::getUrl('index'))->assertOk();
        $this->get(EventResource::getUrl('create'))->assertOk();

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $startsAt = now()->addDays(2)->setTime(18, 0)->format('Y-m-d H:i');

        $editor = app(EventBackofficeEditor::class);
        $event = $editor->createEvent([
            'title' => 'Тренировка из Filament',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => $startsAt,
            'duration_minutes' => 90,
            'max_participants' => 10,
        ]);

        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame($venue->primaryCourt()->first()->id, $event->venue_court_id);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
        $this->assertSame(1, $event->participants()->count());
        $this->assertSame(1, $event->booking()->count());
        $this->get(EventResource::getUrl('edit', ['record' => $event]))->assertOk();

        $data = [
            'title' => 'Обновлённая тренировка',
            'venue_id' => $venue->id,
            'venue_court_id' => $event->venue_court_id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'description' => 'Обновлено в админке',
            'starts_at' => $startsAt,
            'duration_minutes' => 90,
            'max_participants' => 10,
            'booking_scope' => 'whole',
        ];
        $updated = $editor->updateEvent($event, $data);
        $this->assertSame('Обновлённая тренировка', $updated->title);
        $this->assertSame('Обновлено в админке', $updated->description);
        $this->assertSame(1, $updated->booking()->count());
    }

    public function test_event_management_is_delegated_separately_from_venue_management(): void
    {
        $super = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);
        $this->assertFalse(EventResource::canViewAny());

        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_EVENT_MANAGE->value,
        ]);
        $this->flushSession();
        $this->actingAs($manager);
        $this->assertTrue(EventResource::canViewAny());
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(EventResource::getUrl('index'))->assertOk();
    }
}
