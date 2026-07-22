<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

final class CreateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', Rule::in(range(30, 480, 30))],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'duration_minutes.required' => 'Выберите длительность мероприятия.',
            'duration_minutes.in' => 'Выберите длительность от 30 минут до 8 часов с шагом 30 минут.',
            'max_participants.min' => 'Вместимость должна учитывать организатора и хотя бы одного участника.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('starts_at')) {
                return;
            }

            try {
                $timezone = (string) config('app.timezone', 'Europe/Moscow');
                $startsAt = CarbonImmutable::parse((string) $this->input('starts_at'), $timezone);
            } catch (Throwable) {
                return;
            }

            if ($startsAt->lessThan($this->minimumStartsAt())) {
                $validator->errors()->add('starts_at', 'Начало должно быть не раньше чем через 15 минут.');
            }

        });
    }

    private function minimumStartsAt(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'))
            ->addMinutes(15)
            ->ceilMinute();
    }
}
