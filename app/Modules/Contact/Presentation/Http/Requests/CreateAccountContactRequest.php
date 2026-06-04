<?php

namespace App\Modules\Contact\Presentation\Http\Requests;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
            $type = ContactTypeEnum::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            try {
                new ContactValue($type, (string) $this->input('value'));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('value', $e->getMessage());
            }
        });
    }

    public function toDTO(): CreateContactDTO
    {
        $data = $this->validated();

        return new CreateContactDTO(
            type: ContactTypeEnum::from($data['type']),
            value: (new ContactValue(ContactTypeEnum::from($data['type']), $data['value']))->value(),
            label: $data['label'] ?? null,
        );
    }
}
