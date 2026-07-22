<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }
}
