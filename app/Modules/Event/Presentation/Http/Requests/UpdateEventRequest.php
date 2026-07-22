<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
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
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'max_participants.min' => 'Вместимость должна учитывать организатора и хотя бы одного участника.',
        ];
    }
}
