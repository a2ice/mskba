<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventResultPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['present', 'array', 'max:20'],
            'tags.*.user_id' => ['required', 'integer', 'distinct'],
            'tags.*.x' => ['required', 'numeric', 'between:0,100'],
            'tags.*.y' => ['required', 'numeric', 'between:0,100'],
        ];
    }
}
