<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('add_venue') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', Rule::enum(VenueTypeEnum::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'raw_address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'type' => 'тип площадки',
            'description' => 'описание',
            'raw_address' => 'адрес',
        ];
    }
}
