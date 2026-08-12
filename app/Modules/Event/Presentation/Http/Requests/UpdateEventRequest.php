<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'venue_id' => ['required_with:starts_at,duration_minutes', 'integer', 'exists:venues,id'],
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required_with:venue_id,duration_minutes', 'date'],
            'duration_minutes' => ['required_with:venue_id,starts_at', 'integer', 'min:1', 'max:1440'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'venue_id.required_with' => 'Выберите площадку для переноса мероприятия.',
            'starts_at.required_with' => 'Укажите новое время начала.',
            'duration_minutes.required_with' => 'Выберите длительность мероприятия.',
            'duration_minutes.min' => 'Длительность должна быть больше нуля.',
            'duration_minutes.max' => 'Мероприятие должно завершиться в течение суток.',
            'max_participants.min' => 'Вместимость должна учитывать организатора и хотя бы одного участника.',
        ];
    }
}
