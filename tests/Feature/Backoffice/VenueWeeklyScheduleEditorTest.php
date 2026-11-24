<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueWeeklyScheduleEditorTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
    }

    public function test_backoffice_create_persists_weekly_defaults_overrides_and_primary_court(): void
    {
        $this->signIn();

        $editor = app(VenueBackofficeEditor::class);
        $venue = $editor->save([
            'name' => 'Новая уличная площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
                'initial_courts' => [[
                    'name' => 'Корт 1',
                    'environment' => 'outdoor',
                    'hoops_count' => 2,
                    'amenity_ids' => [],
                ]],
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'schedule_timezone' => 'Europe/Moscow',
            'weekly_default_intervals' => [
                ['starts_at' => '10:00', 'ends_at' => '22:00'],
            ],
            'weekday_overrides' => [
                ['day_of_week' => 6, 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '20:00'],
                ]],
                ['day_of_week' => 7, 'is_closed' => true, 'intervals' => []],
            ],
            'schedule_exceptions' => [
                ['date' => '2026-12-31', 'is_closed' => true, 'intervals' => []],
            ],
        ]);

        $this->assertSame(1, $venue->courts()->count());
        $this->assertSame('Корт 1', $venue->primaryCourt()->firstOrFail()->name);
        $schedule = $venue->schedule()->firstOrFail();
        $this->assertSame(6, $schedule->intervals()->count());
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 6,
            'starts_at' => '12:00',
            'ends_at' => '20:00',
        ]);
        $this->assertDatabaseMissing('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 7,
        ]);
        $this->assertDatabaseHas('venue_schedule_exceptions', [
            'venue_schedule_id' => $schedule->id,
            'date' => '2026-12-31',
            'is_closed' => true,
        ]);

        $fields = $editor->fields($venue);
        $this->assertSame([['starts_at' => '10:00', 'ends_at' => '22:00']], $fields['weekly_default_intervals']);
        $this->assertSame([6, 7], array_column($fields['weekday_overrides'], 'day_of_week'));
        $this->assertTrue($fields['weekday_overrides'][1]['is_closed']);
        $this->assertSame('2026-12-31', $fields['schedule_exceptions'][0]['date']);
    }

    public function test_weekday_override_requires_unique_days_and_valid_hours(): void
    {
        $this->signIn();
        $editor = app(VenueBackofficeEditor::class);
        $base = [
            'name' => 'Тест расписания',
            'type' => VenueTypeEnum::STREET_COURT->value,
                'initial_courts' => [[
                    'name' => 'Корт 1',
                    'environment' => 'outdoor',
                    'hoops_count' => 2,
                    'amenity_ids' => [],
                ]],
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'weekly_default_intervals' => [['starts_at' => '10:00', 'ends_at' => '22:00']],
        ];

        foreach ([
            [
                ['day_of_week' => 5, 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '18:00'],
                ]],
                ['day_of_week' => 5, 'is_closed' => true, 'intervals' => []],
            ],
            [['day_of_week' => 7, 'is_closed' => false, 'intervals' => []]],
        ] as $overrides) {
            try {
                $editor->save([...$base, 'weekday_overrides' => $overrides]);
                $this->fail('The invalid weekday overrides must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.weekday_overrides', $exception->errors());
            }
        }
    }

    public function test_midnight_is_accepted_for_default_weekday_and_date_exception_and_round_trips(): void
    {
        $this->signIn();

        $editor = app(VenueBackofficeEditor::class);
        $venue = $editor->save([
            'name' => 'Зал до полуночи',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'initial_courts' => [[
                'name' => 'Корт 1',
                'environment' => 'outdoor',
                'hoops_count' => 2,
                'amenity_ids' => [],
            ]],
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'schedule_timezone' => 'Europe/Moscow',
            'weekly_default_intervals' => [
                ['starts_at' => '07:00', 'ends_at' => '16:00'],
                ['starts_at' => '16:00', 'ends_at' => '00:00'],
            ],
            'weekday_overrides' => [
                ['day_of_week' => 6, 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '00:00'],
                ]],
            ],
            'schedule_exceptions' => [
                ['date' => '2026-12-31', 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '18:00', 'ends_at' => '00:00'],
                ]],
            ],
        ]);

        $schedule = $venue->schedule()->firstOrFail();
        $this->assertDatabaseHas('venue_schedule_intervals', [
            'venue_schedule_id' => $schedule->id,
            'day_of_week' => 1,
            'starts_at' => '16:00',
            'ends_at' => '00:00',
        ]);
        $exception = $schedule->exceptions()->whereDate('date', '2026-12-31')->firstOrFail();
        $this->assertDatabaseHas('venue_schedule_exception_intervals', [
            'venue_schedule_exception_id' => $exception->id,
            'starts_at' => '18:00',
            'ends_at' => '00:00',
        ]);

        $fields = $editor->fields($venue->refresh());
        $this->assertSame('00:00', $fields['weekly_default_intervals'][1]['ends_at']);
        $this->assertSame('00:00', $fields['weekday_overrides'][0]['intervals'][0]['ends_at']);
        $this->assertSame('00:00', $fields['schedule_exceptions'][0]['intervals'][0]['ends_at']);

        // A midnight close is exclusive; the previous slot still belongs to
        // the selected date, never to the following day's first working hour.
        $monday = \Carbon\CarbonImmutable::parse('2026-12-28 23:30:00', 'Europe/Moscow');
        $effective = app(\App\Modules\Venue\Application\Services\EffectiveVenueHours::class)
            ->intervals($venue->refresh(), null, $monday);
        $this->assertSame('24:00', $effective->last()->ends_at);
    }

    public function test_midnight_end_still_rejects_an_overlapping_earlier_range(): void
    {
        $this->signIn();
        $this->expectException(ValidationException::class);

        app(VenueBackofficeEditor::class)->save([
            'name' => 'Пересекающиеся интервалы',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'weekly_default_intervals' => [
                ['starts_at' => '07:00', 'ends_at' => '00:00'],
                ['starts_at' => '16:00', 'ends_at' => '20:00'],
            ],
        ]);
    }

    public function test_backoffice_create_with_one_configured_court_and_no_schedule(): void
    {
        $this->signIn();
        $venue = app(VenueBackofficeEditor::class)->save([
            'name' => 'Уличная площадка без расписания',
            'type' => VenueTypeEnum::STREET_COURT->value,
                'initial_courts' => [[
                    'name' => 'Корт 1',
                    'environment' => 'outdoor',
                    'hoops_count' => 2,
                    'amenity_ids' => [],
                ]],
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'weekly_default_intervals' => [],
            'weekday_overrides' => [],
        ]);

        $this->assertSame(1, $venue->courts()->count());
        $this->assertTrue($venue->primaryCourt()->exists());
        $this->assertNull($venue->schedule);
    }
}
