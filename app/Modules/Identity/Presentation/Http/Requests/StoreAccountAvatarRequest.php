<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAccountAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Выберите изображение.',
            'avatar.image' => 'Файл должен быть изображением.',
            'avatar.mimes' => 'Поддерживаются только JPEG, PNG и WebP.',
            'avatar.max' => 'Изображение должно быть не больше 5 МБ.',
            'avatar.dimensions' => 'Размер изображения не должен превышать 6000×6000 пикселей.',
        ];
    }
}
