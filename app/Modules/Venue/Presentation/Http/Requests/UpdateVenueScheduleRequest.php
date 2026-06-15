<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'intervals' => ['array'],
            'intervals.*' => ['array'],
            'intervals.*.*.starts_at' => ['nullable', 'date_format:H:i'],
            'intervals.*.*.ends_at' => ['nullable', 'date_format:H:i'],
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
                        $validator->errors()->add("intervals.$dayOfWeek.$index.starts_at", 'Заполните начало и конец интервала.');

                        continue;
                    }

                    if (is_string($startsAt) && is_string($endsAt) && $startsAt >= $endsAt) {
                        $validator->errors()->add("intervals.$dayOfWeek.$index.ends_at", 'Конец интервала должен быть позже начала.');
                    }
                }
            }
        });
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
}
