<?php

namespace App\Modules\Event\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEventResultPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isBlocked();
    }

    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000']];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Выберите изображение.',
            'photo.image' => 'Файл должен быть изображением.',
            'photo.uploaded' => 'Не удалось загрузить изображение. Выберите файл размером не больше 5 МБ.',
            'photo.mimes' => 'Поддерживаются только JPEG, PNG и WebP.',
            'photo.max' => 'Изображение должно быть не больше 5 МБ.',
            'photo.dimensions' => 'Размер изображения не должен превышать 6000×6000 пикселей.',
        ];
    }
}
