<?php

namespace App\Modules\Content\Presentation\Http\Requests;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveContentItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('content_format')) {
            $this->merge([
                'content_format' => ContentFormatEnum::MARKDOWN->value,
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manage-content') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:1000'],
            'full_description' => ['required', 'string', 'max:50000'],
            'content_format' => ['required', Rule::enum(ContentFormatEnum::class)],
            'type' => ['required', Rule::enum(ContentTypeEnum::class)],
            'related_id' => ['nullable', 'integer', 'min:1'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:1000'],
            'publish_in_feed' => ['nullable', 'boolean'],
            'publish_in_telegram' => ['nullable', 'boolean'],
            'telegram_chat_ids' => ['nullable', 'array'],
            'telegram_chat_ids.*' => [
                'integer',
                Rule::exists('telegram_chats', 'id')->where('is_active', true),
            ],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = ContentTypeEnum::tryFrom((string) $this->input('type'));

                if ($type?->requiresRelatedEntity() && ! $this->filled('related_id')) {
                    $validator->errors()->add('related_id', 'Выберите связанную сущность.');
                }

                $link = trim((string) $this->input('link_url'));
                if ($link !== '' && ! str_starts_with($link, '/') && filter_var($link, FILTER_VALIDATE_URL) === false) {
                    $validator->errors()->add('link_url', 'Укажите внутренний путь или полный URL.');
                }

                if ($this->boolean('publish_in_telegram') && count((array) $this->input('telegram_chat_ids', [])) === 0) {
                    $validator->errors()->add('telegram_chat_ids', 'Выберите хотя бы один Telegram-чат.');
                }
            },
        ];
    }
}
