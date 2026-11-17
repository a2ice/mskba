<?php

namespace Tests\Unit;

use App\Filament\Backoffice\Resources\Venues\VenueWeeklyScheduleForm;
use PHPUnit\Framework\TestCase;

final class VenueWeeklyScheduleFormTest extends TestCase
{
    public function test_week_defaults_expand_to_seven_days_and_override_closed_and_custom_days(): void
    {
        $rows = VenueWeeklyScheduleForm::toStored(
            [['starts_at' => '10:00', 'ends_at' => '22:00']],
            [
                ['day_of_week' => 5, 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '18:00'],
                    ['starts_at' => '19:00', 'ends_at' => '21:00'],
                ]],
                ['day_of_week' => 7, 'is_closed' => true, 'intervals' => []],
            ],
        );

        self::assertCount(7, $rows);
        self::assertSame([1, 2, 3, 4, 5, 5, 6], array_column($rows, 'day_of_week'));
        self::assertSame(['12:00', '19:00'], array_column(array_values(array_filter(
            $rows, fn (array $row): bool => $row['day_of_week'] === 5,
        )), 'starts_at'));
    }

    public function test_existing_per_day_intervals_collapse_into_most_common_week_with_overrides(): void
    {
        $stored = VenueWeeklyScheduleForm::toStored(
            [['starts_at' => '10:00', 'ends_at' => '22:00']],
            [
                ['day_of_week' => 5, 'is_closed' => false, 'intervals' => [
                    ['starts_at' => '12:00', 'ends_at' => '18:00'],
                ]],
                ['day_of_week' => 7, 'is_closed' => true, 'intervals' => []],
            ],
        );
        $form = VenueWeeklyScheduleForm::fromStored(
            array_map(static fn (array $row): object => (object) [
                ...$row,
                'starts_at' => $row['starts_at'].':00',
                'ends_at' => $row['ends_at'].':00',
            ], $stored),
        );
        self::assertSame([['starts_at' => '10:00', 'ends_at' => '22:00']], $form['weekly_default_intervals']);
        self::assertSame([5, 7], array_column($form['weekday_overrides'], 'day_of_week'));
        self::assertTrue($form['weekday_overrides'][1]['is_closed']);
        self::assertSame($stored, VenueWeeklyScheduleForm::toStored(
            $form['weekly_default_intervals'], $form['weekday_overrides'],
        ));
    }

    public function test_empty_legacy_schedule_remains_empty(): void
    {
        self::assertSame([
            'weekly_default_intervals' => [],
            'weekday_overrides' => [],
        ], VenueWeeklyScheduleForm::fromStored([]));
        self::assertSame([], VenueWeeklyScheduleForm::toStored([], []));
    }
}
