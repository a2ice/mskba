<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\Services\VenueTariffGrid;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Native Filament management page. It deliberately does not use public
 * account routes, ownership checks, styles or theme rendering. All writes
 * go through the same per-step tariff service as the account editor.
 */
final class EditVenueTariffs extends Page
{
    protected static string $resource = VenueResource::class;

    protected string $view = 'filament.backoffice.resources.venues.pages.edit-venue-tariffs';

    protected static ?string $title = 'Тарифная сетка';

    public int $venueId;

    public ?int $courtId = null;

    public string $mode = 'weekly';

    public string $date = '';

    /** @var list<array<string, mixed>> */
    public array $ranges = [];

    public function mount(string|int $record): void
    {
        $venue = VenueResource::getEloquentQuery()->findOrFail($record);
        abort_unless(VenueResource::canEdit($venue), 403);

        $this->venueId = (int) $venue->getKey();
        $this->date = CarbonImmutable::now('Europe/Moscow')->format('Y-m-d');
        $this->courtId = request()->integer('court') ?: null;
        $this->mode = in_array(request()->query('mode'), ['weekly', 'date'], true)
            ? (string) request()->query('mode') : 'weekly';
        $requestedDate = request()->query('date');
        if (is_string($requestedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate) === 1) {
            $this->date = $requestedDate;
        }

        // Verify the court belongs to the venue, including when selected by URL.
        $this->court();
        $this->policy();
        $this->addRange();
    }

    public function getTitle(): string
    {
        return 'Тарифная сетка — '.$this->venue()->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            VenueResource::getUrl('index') => 'Площадки',
            VenueResource::getUrl('edit', ['record' => $this->venueId]) => $this->venue()->name,
            'Тарифная сетка',
        ];
    }

    public function venue(): Venue
    {
        $venue = VenueResource::getEloquentQuery()->findOrFail($this->venueId);
        abort_unless(VenueResource::canEdit($venue), 403);

        return $venue;
    }

    public function courts(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->venue()->courts()->get();
    }

    public function court(): ?VenueCourt
    {
        if ($this->courtId === null) {
            return null;
        }

        return $this->venue()->courts()->whereKey($this->courtId)->firstOrFail();
    }

    public function policy(): VenueBookingPolicy
    {
        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $this->venueId)
            ->where('active_marker', true)
            ->first();
        if ($policy === null) {
            throw ValidationException::withMessages([
                'venue' => 'Сначала опубликуйте базовые условия аренды площадки.',
            ]);
        }

        return $policy;
    }

    public function step(): int
    {
        return app(VenueTariffGrid::class)->step($this->policy(), $this->court());
    }

    /** @return array<int, array<string, mixed>> */
    public function effectiveGrid(): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            return [];
        }

        return app(VenueTariffGrid::class)->read(
            $this->venue(), $this->policy(), $this->court(), $this->mode, $this->date
        );
    }

    public function formatPrice(?int $minor): string
    {
        if ($minor === null) {
            return '—';
        }

        return app(MinorAmountParser::class)->format($minor, $this->policy()->currency);
    }

    public function addRange(): void
    {
        abort_unless(count($this->ranges) < 16, 422);
        $step = $this->step();
        $from = intdiv(420 + $step - 1, $step) * $step;
        $to = min($from + $step * 2, 1440);
        $this->ranges[] = [
            'days' => [1, 2, 3, 4, 5],
            'starts_at' => self::clock($from),
            'ends_at' => $to === 1440 ? '00:00' : self::clock($to),
            'operation' => 'set',
            'whole_price_per_step' => '',
            'half_price_per_step' => '',
            'exceptions' => [],
        ];
    }

    public function removeRange(int $index): void
    {
        $this->venue();
        if (array_key_exists($index, $this->ranges)) {
            array_splice($this->ranges, $index, 1);
        }
    }

    public function addException(int $index): void
    {
        $this->venue();
        if (! isset($this->ranges[$index])) {
            return;
        }
        $this->ranges[$index]['exceptions'] ??= [];
        if (count($this->ranges[$index]['exceptions']) >= 100) {
            return;
        }

        $this->ranges[$index]['exceptions'][] = [
            'starts_at' => $this->ranges[$index]['starts_at'],
            'day_of_week' => '',
            'operation' => 'set',
            'whole_price_per_step' => '',
            'half_price_per_step' => '',
        ];
    }

    public function removeException(int $rangeIndex, int $exceptionIndex): void
    {
        $this->venue();
        if (isset($this->ranges[$rangeIndex]['exceptions'][$exceptionIndex])) {
            array_splice($this->ranges[$rangeIndex]['exceptions'], $exceptionIndex, 1);
        }
    }

    public function editStep(int $day, string $time): void
    {
        $this->venue();
        $step = $this->step();
        if (! preg_match('/^\d{2}:\d{2}$/', $time) || ! in_array($day, range(1, 7), true)) {
            return;
        }

        $endMinutes = self::minutes($time) + $step;
        if ($endMinutes > 1440) {
            return;
        }

        $current = null;
        foreach ($this->effectiveGrid() as $dayRows) {
            if ($dayRows['day'] !== $day) {
                continue;
            }
            foreach ($dayRows['ranges'] as $range) {
                foreach ($range['cells'] as $cell) {
                    if ($cell['time'] === $time) {
                        $current = $cell;
                        break 3;
                    }
                }
            }
        }

        if ($current === null) {
            return;
        }
        // Replacing the draft avoids collisions with unsaved bulk ranges.
        $this->ranges = [[
            'days' => [$day],
            'starts_at' => $time,
            'ends_at' => $endMinutes === 1440 ? '00:00' : self::clock($endMinutes),
            'operation' => 'set',
            'whole_price_per_step' => $this->formatPrice($current['whole']),
            'half_price_per_step' => $this->formatPrice($current['half']),
            'exceptions' => [],
        ]];
    }

    public function saveRanges(): void
    {
        $venue = $this->venue();
        $policy = $this->policy();
        $court = $this->court();

        $data = Validator::make([
            'mode' => $this->mode,
            'date' => $this->date,
            'ranges' => $this->ranges,
        ], [
            'mode' => ['required', Rule::in(['weekly', 'date'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'ranges' => ['required', 'array', 'min:1', 'max:16'],
            'ranges.*.days' => ['required_if:mode,weekly', 'array', 'max:7'],
            'ranges.*.days.*' => ['integer', 'between:1,7', 'distinct'],
            'ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.ends_at' => ['required', 'date_format:H:i'],
            'ranges.*.operation' => ['required', Rule::in(['set', 'clear'])],
            'ranges.*.whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.exceptions' => ['array', 'max:100'],
            'ranges.*.exceptions.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.exceptions.*.day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'ranges.*.exceptions.*.operation' => ['required', Rule::in(['set', 'clear'])],
            'ranges.*.exceptions.*.whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.exceptions.*.half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
        ])->validate();

        $count = app(VenueTariffGrid::class)->applyMany(
            $venue, $policy, $court, $data['mode'], $data['date'],
            $data['ranges'], auth('web')->id(),
        );

        Notification::make()
            ->success()
            ->title('Тарифы сохранены')
            ->body('Обновлено временных шагов: '.$count.'. Источник интеграции не изменён.')
            ->send();
    }

    private static function minutes(string $clock): int
    {
        return ((int) substr($clock, 0, 2)) * 60 + (int) substr($clock, 3, 2);
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
