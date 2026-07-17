<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin-panel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                VenueStatusEnum::UNCONFIRMED->value,
                VenueStatusEnum::BLOCKED->value,
            ])],
            'message' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === VenueStatusEnum::BLOCKED->value),
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    public function statusEnum(): VenueStatusEnum
    {
        return VenueStatusEnum::from($this->validated('status'));
    }

    public function messageText(): string
    {
        $message = $this->validated('message');

        return is_string($message) ? trim($message) : '';
    }
}
