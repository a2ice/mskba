<?php

namespace App\Modules\Coordination\Presentation\Http\Requests;

use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use Illuminate\Foundation\Http\FormRequest;

final class SuggestPollOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $session = $this->route('coordination');
        $poll = $session instanceof CoordinationSession
            ? $session->polls()->oldest('id')->first()
            : null;

        return match ($poll?->subject_type) {
            PollSubjectTypeEnum::DATE => ['option' => ['required', 'date_format:Y-m-d']],
            PollSubjectTypeEnum::TIME => ['option' => ['required', 'date_format:H:i']],
            PollSubjectTypeEnum::DATETIME => ['option' => ['required', 'date_format:Y-m-d\TH:i']],
            PollSubjectTypeEnum::TIME_INTERVAL => [
                'option' => ['required', 'array:starts_at,ends_at'],
                'option.starts_at' => ['required', 'date_format:H:i'],
                'option.ends_at' => ['required', 'date_format:H:i'],
            ],
            PollSubjectTypeEnum::VENUE => ['option' => ['required', 'integer', 'exists:venues,id']],
            default => ['option' => ['required', 'string', 'max:255']],
        };
    }
}
