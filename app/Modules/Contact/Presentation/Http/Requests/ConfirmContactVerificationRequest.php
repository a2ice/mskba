<?php

namespace App\Modules\Contact\Presentation\Http\Requests;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmContactVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Введите код подтверждения.',
            'code.regex' => 'Код подтверждения должен состоять из 6 цифр.',
        ];
    }

    public function toDTO(): ConfirmContactVerificationDTO
    {
        $data = $this->validated();

        return new ConfirmContactVerificationDTO(
            code: $data['code'],
        );
    }
}
