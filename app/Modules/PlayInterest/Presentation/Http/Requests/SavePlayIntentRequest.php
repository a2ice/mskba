<?php

namespace App\Modules\PlayInterest\Presentation\Http\Requests;

use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SavePlayIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['sometimes', 'uuid'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->whereNull('deleted_at')->where('status', 'confirmed')],
            'include_similar_venues' => ['sometimes', 'boolean'],
            'center_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'center_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'between:1,30'],
            'cadence' => ['required', Rule::in(['flexible', 'once', 'regular'])],
            'weekdays' => ['sometimes', 'array', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'dates' => ['sometimes', 'array', 'max:30'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'windows' => ['sometimes', 'array', 'max:28'],
            'windows.*.weekday' => ['nullable', 'integer', 'between:1,7'],
            'windows.*.date' => ['nullable', 'date_format:Y-m-d'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
            'windows.*.end_next_day' => ['sometimes', 'boolean'],
            'formats' => ['sometimes', 'array', 'max:5'],
            'formats.*' => ['required', Rule::in(['1x1', '2x2', '3x3', '4x4', '5x5']), 'distinct'],
            'duration_minutes' => ['nullable', 'integer', 'between:30,480', 'multiple_of:30'],
            'access_mode' => ['sometimes', Rule::in(['any', 'free', 'paid'])],
            'amenity_preferences' => ['sometimes', 'array', 'max:80'],
            'amenity_preferences.*.amenity_id' => [
                'required', 'integer',
                Rule::exists('amenities', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'amenity_preferences.*.priority' => ['required', Rule::in(['preferred', 'required'])],
            'notify_matches' => ['sometimes', 'boolean'],
            'notify_offers' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $this->all();
            $venueId = $data['venue_id'] ?? null;
            $similar = filter_var($data['include_similar_venues'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($venueId === null && ! $similar) {
                $validator->errors()->add('include_similar_venues', 'Для поиска без площадки включи подбор по условиям.');
            }
            if (($venueId === null || $similar) && (
                ! is_numeric($data['center_latitude'] ?? null)
                || ! is_numeric($data['center_longitude'] ?? null)
                || ! isset($data['radius_km'])
            )) {
                // With a preferred venue, the center can be derived in the handler.
                if ($venueId === null) {
                    $validator->errors()->add('center_latitude', 'Для поиска по условиям выбери центр и радиус.');
                }
            }
            if (isset($data['center_latitude']) xor isset($data['center_longitude'])) {
                $validator->errors()->add('center_longitude', 'Координаты должны быть указаны парой.');
            }

            $cadence = $data['cadence'] ?? null;
            $dates = $data['dates'] ?? [];
            if ($cadence !== 'once' && $dates !== []) {
                $validator->errors()->add('dates', 'Конкретные даты доступны только для разового интереса.');
            }
            if ($cadence !== 'once' && (isset($data['period_from']) || isset($data['period_until']))) {
                $validator->errors()->add('period_from', 'Период поиска используется только для разовой игры.');
            }
            if ($cadence === 'once' && $dates === [] && (! isset($data['period_from']) || ! isset($data['period_until']))) {
                $validator->errors()->add('dates', 'Выбери даты или период для разовой игры.');
            }
            if ($cadence === 'once' && $dates !== [] && (isset($data['period_from']) || isset($data['period_until']))) {
                $validator->errors()->add('dates', 'Укажи либо конкретные даты, либо период поиска.');
            }
            $today = now()->toDateString();
            foreach ((array) $dates as $index => $date) {
                if (is_string($date) && $date < $today) {
                    $validator->errors()->add("dates.{$index}", 'Нельзя выбрать прошедшую дату.');
                }
            }
            if ($cadence === 'once' && is_string($data['period_from'] ?? null) && $data['period_from'] < $today) {
                $validator->errors()->add('period_from', 'Период не может начинаться в прошлом.');
            }
            if ($cadence === 'once' && is_string($data['period_until'] ?? null)
                && $data['period_until'] > now()->addDays(30)->toDateString()) {
                $validator->errors()->add('period_until', 'Горизонт разового поиска не более 30 дней.');
            }

            $seenAmenities = [];
            foreach ((array) ($data['amenity_preferences'] ?? []) as $index => $item) {
                if (! is_array($item) || ! isset($item['amenity_id'])) {
                    continue;
                }
                $id = (int) $item['amenity_id'];
                if (isset($seenAmenities[$id])) {
                    $validator->errors()->add("amenity_preferences.{$index}.amenity_id", 'Удобство указано дважды.');
                }
                $seenAmenities[$id] = true;
            }

            foreach ((array) ($data['windows'] ?? []) as $index => $window) {
                if (! is_array($window)) {
                    continue;
                }
                $start = $window['start_time'] ?? null;
                $end = $window['end_time'] ?? null;
                if (! is_string($start) || ! is_string($end)
                    || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $start)
                    || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $end)) {
                    continue;
                }
                $overnight = filter_var($window['end_next_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $s = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
                $e = (int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2) + ($overnight ? 1440 : 0);
                if ($e <= $s || $e - $s > 480) {
                    $validator->errors()->add("windows.{$index}.end_time", 'Неверный интервал времени.');
                }
                if ($cadence === 'once' && isset($window['weekday']) && $window['weekday'] !== null) {
                    $validator->errors()->add("windows.{$index}.weekday", 'Для разовой игры используй дату, не день недели.');
                }
                if ($cadence !== 'once' && isset($window['date']) && $window['date'] !== null) {
                    $validator->errors()->add("windows.{$index}.date", 'Для повторяющегося поиска используй день недели.');
                }
            }

            // A removed/changed amenity must never silently become a strict condition.
            if ($validator->errors()->isEmpty() && $venueId !== null) {
                $venue = Venue::query()->find($venueId);
                if ($venue !== null && $venue->trashed()) {
                    $validator->errors()->add('venue_id', 'Площадка недоступна.');
                }
            }
        }];
    }
}
