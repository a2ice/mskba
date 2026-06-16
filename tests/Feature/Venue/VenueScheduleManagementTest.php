<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_owner_can_open_schedule_form(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'name' => 'Площадка с формой расписания',
            'alias' => 'schedule-form-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create([
            'timezone' => 'Europe/Moscow',
        ]);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ]);

        $this
            ->actingAs($user)
            ->get(route('account.venues.schedule.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Расписание: Площадка с формой расписания')
            ->assertSee('Понедельник')
            ->assertSee('Интервал 1')
            ->assertSee('Добавить интервал')
            ->assertSee('Удалить')
            ->assertSee('Применить первый заполненный день ко всем')
            ->assertSee('Сбросить у всех')
            ->assertSee('data-venue-schedule-add-interval', false)
            ->assertSee('data-venue-schedule-apply-all', false)
            ->assertSee('data-venue-schedule-reset-all', false)
            ->assertSee('value="10:00"', false)
            ->assertSee('value="12:00"', false);
    }

    public function test_bootstrap_owner_can_update_schedule_intervals(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'schedule-update-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $venue->alias), [
                'timezone' => 'Europe/Moscow',
                'intervals' => [
                    1 => [
                        ['starts_at' => '10:00', 'ends_at' => '12:30'],
                        ['starts_at' => '18:00', 'ends_at' => '21:00'],
                    ],
                    3 => [
                        ['starts_at' => '09:00', 'ends_at' => '11:00'],
                    ],
                ],
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $venue->alias));

        $schedule = $venue->schedule()->firstOrFail();

        $this->assertSame('Europe/Moscow', $schedule->timezone);
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:30',
        ]);
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 1,
            'starts_at' => '18:00',
            'ends_at' => '21:00',
        ]);
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 3,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
        ]);
    }

    public function test_schedule_update_replaces_old_intervals(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'schedule-replace-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create();
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => 5,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
        ]);

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $venue->alias), [
                'timezone' => 'Europe/Moscow',
                'intervals' => [
                    2 => [
                        ['starts_at' => '15:00', 'ends_at' => '17:00'],
                    ],
                ],
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $venue->alias));

        $this->assertDatabaseMissing('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 5,
            'starts_at' => '08:00',
        ]);
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 2,
            'starts_at' => '15:00',
            'ends_at' => '17:00',
        ]);
    }

    public function test_schedule_update_validates_interval_order(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'schedule-validation-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $venue->alias), [
                'timezone' => 'Europe/Moscow',
                'intervals' => [
                    1 => [
                        ['starts_at' => '12:00', 'ends_at' => '10:00'],
                    ],
                ],
            ])
            ->assertSessionHasErrors(['intervals.1.0.ends_at']);
    }

    public function test_user_cannot_update_another_venue_schedule(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($owner),
            'alias' => 'schedule-denied-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($intruder)
            ->put(route('account.venues.schedule.update', $venue->alias), [
                'timezone' => 'Europe/Moscow',
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $venue->alias))
            ->assertSessionHas('error', 'Доступ запрещен');

        $this->assertDatabaseMissing('venue_schedules', [
            'venue_id' => $venue->id,
        ]);
    }

    private function actorIdFor(User $user): int
    {
        return app(CurrentActorResolver::class)->resolve($user, null)->id;
    }
}
