<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitVenueModerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! ($this->user()?->isBlocked() ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messageText(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : null;
    }
}
