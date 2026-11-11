<?php

namespace Tests\Unit\VenueIntegration;

use App\Modules\VenueIntegration\Infrastructure\Adapters\FindSportReadOnlyAdapter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FindSportReadOnlyAdapterTest extends TestCase
{
    private function html(array $overrides = []): string
    {
        $page = array_replace_recursive([
            'playground' => [
                'id' => 4805,
                'start' => 25200,
                'finish' => 86400,
                'timeStep' => 1800,
                'minBookingInterval' => 3600,
                'timezone' => 'Europe/Moscow',
            ],
            'schedules' => [['id' => 9068, 'name' => '']],
            'priceManagerData' => ['prices' => [
                [
                    'id' => 111448,
                    'part' => 12,
                    'weekdays' => 62,
                    'start' => 25200,
                    'finish' => 86400,
                    'outer' => 3250,
                    'inner' => 3250,
                    'options' => [],
                    'discount_id' => null,
                    'season' => ['periodStart' => '2026-11-01', 'periodFinish' => '2027-01-01'],
                ],
            ]],
        ], $overrides);

        return '<script>new pagesPlaygroundView('
            .json_encode($page, JSON_THROW_ON_ERROR).');</script>';
    }

    private function start(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-13', 'Europe/Moscow');
    }

    public function test_it_reads_weekly_booked_map_and_prorates_hourly_prices_to_kopecks(): void
    {
        $week = json_encode([
            '2026-11-13' => [
                '08:00' => ['9068' => 12],
                '08:30' => ['9068' => 12],
            ],
            '2026-11-14' => [],
        ], JSON_THROW_ON_ERROR);

        $result = (new FindSportReadOnlyAdapter)->parse($this->html(), $week, 4805, $this->start());
        $this->assertSame(9068, (int) $result['external_court_id']);
        $this->assertSame(30, $result['step_minutes']);
        $this->assertSame(60, $result['minimum_minutes']);
        $this->assertSame('RUB', $result['price_currency']);
        $this->assertCount(7, $result['days']);

        $friday = $result['days'][0];
        $this->assertTrue($friday['complete']);
        $this->assertSame(34, $friday['slot_count']);
        $this->assertSame(2, $friday['counts']['booked']);
        $this->assertSame(32, $friday['counts']['available']);
        $this->assertSame('07:00', $friday['slots'][0]['time']);
        $this->assertSame('available', $friday['slots'][0]['status']);
        $this->assertSame(162500, $friday['slots'][0]['price_minor']);
        $this->assertSame('booked', $friday['slots'][2]['status']);
        $this->assertSame('booked', $friday['slots'][3]['status']);

        // Saturday was returned by the provider but has no weekday rate;
        // absence of a price does not imply a bookable interval.
        $this->assertTrue($result['days'][1]['complete']);
        $this->assertSame(34, $result['days'][1]['counts']['unavailable']);
        // A missing date key means unknown, even when calendar rules exist.
        $this->assertFalse($result['days'][2]['complete']);
        $this->assertSame(34, $result['days'][2]['counts']['unknown']);
    }

    public function test_unrecognized_occupancy_masks_are_unknown_not_free(): void
    {
        $week = json_encode([
            '2026-11-13' => ['08:00' => ['9068' => 99]],
        ], JSON_THROW_ON_ERROR);
        $result = (new FindSportReadOnlyAdapter)->parse($this->html(), $week, 4805, $this->start());

        $this->assertSame('unknown', $result['days'][0]['slots'][2]['status']);
    }

    public function test_wrong_playground_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new FindSportReadOnlyAdapter)->parse($this->html(), '{}', 9999, $this->start());
    }

    public function test_missing_page_payload_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new FindSportReadOnlyAdapter)->parse('<html><body></body></html>', '{}', 4805, $this->start());
    }
}
