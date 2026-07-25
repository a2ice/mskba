<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoteInPollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isBlocked();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'option_ids' => ['required', 'array', 'min:1', 'max:20'],
            'option_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
