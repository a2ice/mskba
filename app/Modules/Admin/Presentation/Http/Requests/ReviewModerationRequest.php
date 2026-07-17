<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewModerationRequest extends FormRequest
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
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messageText(): string
    {
        $message = $this->validated('message');

        return is_string($message) ? trim($message) : '';
    }
}
