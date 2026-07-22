<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'intervals' => ['array'],
            'intervals.*' => ['array', 'max:3'],
            'intervals.*.*.starts_at' => ['nullable', 'date_format:H:i'],
            'intervals.*.*.ends_at' => ['nullable', 'date_format:H:i'],
            'exceptions' => ['array', 'max:100'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'exceptions.*.is_closed' => ['nullable', 'boolean'],
            'exceptions.*.intervals' => ['array', 'max:3'],
            'exceptions.*.intervals.*.starts_at' => ['nullable', 'date_format:H:i'],
            'exceptions.*.intervals.*.ends_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->input('intervals', []) as $dayOfWeek => $intervals) {
                if (! is_numeric($dayOfWeek) || (int) $dayOfWeek < 1 || (int) $dayOfWeek > 7) {
                    $validator->errors()->add("intervals.$dayOfWeek", 'Некорректный день недели.');

                    continue;
                }

                if (! is_array($intervals)) {
                    continue;
                }

                $this->validateIntervals($validator, $intervals, "intervals.$dayOfWeek");
            }

            foreach ($this->input('exceptions', []) as $index => $exception) {
                if (! is_array($exception)) {
                    continue;
                }

                $intervals = $exception['intervals'] ?? [];
                $isClosed = filter_var($exception['is_closed'] ?? false, FILTER_VALIDATE_BOOL);
                if (! is_array($intervals)) {
                    $intervals = [];
                }

                $filled = array_filter($intervals, fn ($interval): bool => is_array($interval)
                    && (($interval['starts_at'] ?? '') !== '' || ($interval['ends_at'] ?? '') !== ''));

                if ($isClosed && $filled !== []) {
                    $validator->errors()->add("exceptions.$index.intervals", 'У закрытого дня не должно быть интервалов.');
                } elseif (! $isClosed && $filled === []) {
                    $validator->errors()->add("exceptions.$index.intervals", 'Укажите часы работы или отметьте день закрытым.');
                }

                $this->validateIntervals($validator, $intervals, "exceptions.$index.intervals");
            }
        });
    }

    private function validateIntervals($validator, array $intervals, string $path): void
    {
        $complete = [];
        foreach ($intervals as $index => $interval) {
            if (! is_array($interval)) {
                continue;
            }

            $startsAt = $interval['starts_at'] ?? null;
            $endsAt = $interval['ends_at'] ?? null;

            if (($startsAt === null || $startsAt === '') && ($endsAt === null || $endsAt === '')) {
                continue;
            }

            if ($startsAt === null || $startsAt === '' || $endsAt === null || $endsAt === '') {
                $validator->errors()->add("$path.$index.starts_at", 'Заполните начало и конец интервала.');

                continue;
            }

            if (is_string($startsAt) && is_string($endsAt) && $startsAt >= $endsAt) {
                $validator->errors()->add("$path.$index.ends_at", 'Конец интервала должен быть позже начала.');

                continue;
            }

            if (is_string($startsAt) && is_string($endsAt)) {
                $complete[] = ['index' => $index, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
            }
        }

        usort($complete, fn (array $left, array $right): int => $left['starts_at'] <=> $right['starts_at']);
        for ($index = 1; $index < count($complete); $index++) {
            if ($complete[$index]['starts_at'] < $complete[$index - 1]['ends_at']) {
                $validator->errors()->add(
                    "$path.{$complete[$index]['index']}.starts_at",
                    'Интервалы не должны пересекаться.'
                );
            }
        }
    }

    /**
     * @return array<int, array<int, array{starts_at: string, ends_at: string}>>
     */
    public function intervalsByDay(): array
    {
        $intervalsByDay = [];

        foreach ($this->validated('intervals', []) as $dayOfWeek => $intervals) {
            if ((int) $dayOfWeek < 1 || (int) $dayOfWeek > 7 || ! is_array($intervals)) {
                continue;
            }

            foreach ($intervals as $interval) {
                if (! is_array($interval)) {
                    continue;
                }

                $startsAt = $interval['starts_at'] ?? null;
                $endsAt = $interval['ends_at'] ?? null;

                if (! is_string($startsAt) || ! is_string($endsAt) || $startsAt === '' || $endsAt === '') {
                    continue;
                }

                $intervalsByDay[(int) $dayOfWeek][] = [
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ];
            }
        }

        return $intervalsByDay;
    }

    public function timezone(): string
    {
        return (string) $this->validated('timezone', 'Europe/Moscow');
    }

    public function operationalStatus(): ?VenueOperationalStatusEnum
    {
        $status = $this->validated('operational_status');

        return is_string($status) ? VenueOperationalStatusEnum::from($status) : null;
    }

    /** @return array<int, array{date: string, is_closed: bool, intervals: array<int, array{starts_at: string, ends_at: string}>}> */
    public function exceptions(): array
    {
        $result = [];
        foreach ($this->validated('exceptions', []) as $exception) {
            if (! is_array($exception) || ! is_string($exception['date'] ?? null)) {
                continue;
            }

            $intervals = [];
            foreach ($exception['intervals'] ?? [] as $interval) {
                if (is_array($interval) && is_string($interval['starts_at'] ?? null) && is_string($interval['ends_at'] ?? null)
                    && $interval['starts_at'] !== '' && $interval['ends_at'] !== '') {
                    $intervals[] = ['starts_at' => $interval['starts_at'], 'ends_at' => $interval['ends_at']];
                }
            }
            usort($intervals, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);
            $result[] = [
                'date' => $exception['date'],
                'is_closed' => filter_var($exception['is_closed'] ?? false, FILTER_VALIDATE_BOOL),
                'intervals' => $intervals,
            ];
        }

        return $result;
    }
}
