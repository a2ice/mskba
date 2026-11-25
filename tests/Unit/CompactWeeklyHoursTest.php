<?php

namespace Tests\Unit;

use App\Modules\Venue\Application\Services\CompactWeeklyHours;
use PHPUnit\Framework\TestCase;

final class CompactWeeklyHoursTest extends TestCase
{
    public function test_identical_week_and_weekday_weekend_split(): void
    {
        $work = $this->hours('09:00', '21:00');
        $weekend = $this->hours('10:00', '22:00');

        $this->assertSame([['label' => '', 'hours' => '09:00–21:00']],
            CompactWeeklyHours::rows($this->week(array_fill(0, 7, $work))));
        $this->assertSame([
            ['label' => 'Будни', 'hours' => '09:00–21:00'],
            ['label' => 'Выходные', 'hours' => '10:00–22:00'],
        ], CompactWeeklyHours::rows($this->week([...array_fill(0, 5, $work), $weekend, $weekend])));
    }

    public function test_arbitrary_and_non_consecutive_days_preserve_breaks_and_midnight(): void
    {
        $work = $this->hours('09:00', '21:00');
        $late = $this->hours('09:00', '23:00');
        $weekend = $this->hours('10:00', '22:00');
        $this->assertSame([
            ['label' => 'Пн–Чт', 'hours' => '09:00–21:00'],
            ['label' => 'Пт', 'hours' => '09:00–23:00'],
            ['label' => 'Сб–Вс', 'hours' => '10:00–22:00'],
        ], CompactWeeklyHours::rows($this->week([$work, $work, $work, $work, $late, $weekend, $weekend])));

        $split = [...$this->hours('09:00', '13:00'), ...$this->hours('16:00', '24:00')];
        $this->assertSame([
            ['label' => 'Пн–Вт, Чт', 'hours' => '09:00–13:00, 16:00–00:00'],
            ['label' => 'Ср, Пт', 'hours' => 'Не работает'],
            ['label' => 'Сб–Вс', 'hours' => 'Режим работы не указан'],
        ], CompactWeeklyHours::rows($this->week([$split, $split, [], $split, [], null, null])));
        $this->assertSame([['label' => '', 'hours' => 'Режим работы не указан']], CompactWeeklyHours::rows([]));
        $this->assertSame([['label' => '', 'hours' => 'Не работает']],
            CompactWeeklyHours::rows($this->week(array_fill(0, 7, []))));
        $this->assertSame('09:00–00:00', CompactWeeklyHours::intervalLabel($this->hours('09:00', '24:00')));
    }

    private function hours(string $from, string $to): array
    {
        return [['startsAt' => $from, 'endsAt' => $to]];
    }

    private function week(array $intervals): array
    {
        return array_map(fn (string $weekday, $hours): array => ['weekday' => $weekday, 'intervals' => $hours],
            ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'], $intervals);
    }
}
