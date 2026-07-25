<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideCoordinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['option_id' => ['required', 'integer']];
    }
}
