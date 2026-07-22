<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
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
                'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED->value,
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
        $this->assertSame(VenueOperationalStatusEnum::TEMPORARILY_CLOSED, $venue->refresh()->operational_status);
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

    public function test_schedule_update_rejects_overlapping_intervals(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['created_by_actor_id' => $this->actorIdFor($user)]);

        $this->actingAs($user)->put(route('account.venues.schedule.update', $venue->routeIdentifier()), [
            'timezone' => 'Europe/Moscow',
            'intervals' => [1 => [
                ['starts_at' => '10:00', 'ends_at' => '13:00'],
                ['starts_at' => '12:00', 'ends_at' => '14:00'],
            ]],
        ])->assertSessionHasErrors(['intervals.1.1.starts_at']);
    }

    public function test_owner_can_store_closed_and_changed_hours_exceptions(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['created_by_actor_id' => $this->actorIdFor($user)]);

        $this->actingAs($user)->put(route('account.venues.schedule.update', $venue->routeIdentifier()), [
            'timezone' => 'Europe/Moscow',
            'exceptions' => [
                ['date' => '2026-08-01', 'is_closed' => '1', 'intervals' => []],
                ['date' => '2026-08-02', 'is_closed' => '0', 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '16:00'],
                ]],
            ],
        ])->assertSessionHasNoErrors();

        $schedule = $venue->schedule()->firstOrFail();
        $this->assertDatabaseHas('venue_schedule_exceptions', [
            'venue_schedule_id' => $schedule->id, 'date' => '2026-08-01', 'is_closed' => true,
        ]);
        $exceptionId = $schedule->exceptions()->whereDate('date', '2026-08-02')->value('id');
        $this->assertDatabaseHas('venue_schedule_exception_intervals', [
            'venue_schedule_exception_id' => $exceptionId, 'starts_at' => '12:00', 'ends_at' => '16:00',
        ]);
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

    public function test_schedule_can_be_changed_for_confirmed_venue_but_not_for_blocked_venue(): void
    {
        $user = User::factory()->create();
        $confirmedVenue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'confirmed-schedule-venue',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $blockedVenue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'blocked-schedule-venue',
            'status' => VenueStatusEnum::BLOCKED,
        ]);

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $confirmedVenue->alias), [
                'timezone' => 'Europe/Moscow',
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $confirmedVenue->alias));

        $this->assertDatabaseHas('venue_schedules', ['venue_id' => $confirmedVenue->id]);

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $blockedVenue->alias), [
                'timezone' => 'Europe/Moscow',
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $blockedVenue->alias))
            ->assertSessionHas('error', 'Доступ запрещен');

        $this->assertDatabaseMissing('venue_schedules', ['venue_id' => $blockedVenue->id]);
    }

    public function test_schedule_cannot_be_changed_for_deleted_venue(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'alias' => 'deleted-schedule-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $venue->delete();

        $this
            ->actingAs($user)
            ->put(route('account.venues.schedule.update', $venue->alias), [
                'timezone' => 'Europe/Moscow',
            ])
            ->assertRedirect(route('account.venues.schedule.edit', $venue->alias))
            ->assertSessionHas('error', 'Площадка не найдена');

        $this->assertDatabaseMissing('venue_schedules', ['venue_id' => $venue->id]);
    }

    private function actorIdFor(User $user): int
    {
        return app(CurrentActorResolver::class)->resolve($user, null)->id;
    }
}
