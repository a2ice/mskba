<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['result_description' => ['nullable', 'string', 'max:10000']];
    }
}
