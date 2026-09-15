<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVenueConditionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isBlocked();
    }

    public function rules(): array
    {
        return [
            'access_type' => ['required', Rule::in(['unknown', 'free', 'paid'])],
            'requires_booking_approval' => ['required', 'boolean'],
        ];
    }
}
