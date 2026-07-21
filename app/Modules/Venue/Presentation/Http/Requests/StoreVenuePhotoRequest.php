<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreVenuePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! ($this->user()?->isBlocked() ?? false);
    }

    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000']];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Выберите изображение.', 'photo.image' => 'Файл должен быть изображением.',
            'photo.mimes' => 'Поддерживаются только JPEG, PNG и WebP.', 'photo.max' => 'Изображение должно быть не больше 5 МБ.',
            'photo.dimensions' => 'Размер изображения не должен превышать 6000×6000 пикселей.',
        ];
    }
}
