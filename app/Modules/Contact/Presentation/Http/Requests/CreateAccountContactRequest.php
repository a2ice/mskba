<?php

namespace App\Modules\Contact\Presentation\Http\Requests;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountContactRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ContactTypeEnum::class)],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('type') !== ContactTypeEnum::EMAIL->value) {
                return;
            }

            if (! filter_var($this->input('value'), FILTER_VALIDATE_EMAIL)) {
                $validator->errors()->add('value', 'Укажите корректный email.');
            }
        });
    }

    public function toDTO(): CreateContactDTO
    {
        $data = $this->validated();

        return new CreateContactDTO(
            type: ContactTypeEnum::from($data['type']),
            value: $data['value'],
            label: $data['label'] ?? null,
        );
    }
}
