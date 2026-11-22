<?php

namespace App\Modules\Content\Presentation\Http\Requests;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
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
        $content = $this->route('contentItem');
        $aliasRules = ['nullable', 'string', 'max:255'];
        if ($this->input('alias') !== $content?->alias) {
            $aliasRules = [
                'bail', ...$aliasRules, 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::notIn(ContentItem::RESERVED_ALIASES),
                Rule::unique(ContentItem::class, 'alias')->ignore($content),
            ];
        }

        return [
            'alias' => $aliasRules,
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:1000'],
            'full_description' => ['required', 'string', 'max:50000'],
            'content_format' => ['required', Rule::enum(ContentFormatEnum::class)],
            'type' => ['required', Rule::enum(ContentTypeEnum::class)],
            'status' => ['sometimes', 'required', Rule::enum(ContentStatusEnum::class)],
            'related_id' => ['nullable', 'integer', 'min:1'],
            'faq_sections_present' => ['sometimes', 'boolean'],
            'faq_section_ids' => ['sometimes', 'array', 'max:100'],
            'faq_section_ids.*' => ['integer', 'distinct', Rule::exists('faq_sections', 'id')],
            'tags' => ['nullable', 'string', 'max:3000'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'project_update_pr_url' => ['nullable', 'url:https', 'max:2048', 'regex:~\\Ahttps://github\\.com/a2ice/mskba/pull/[1-9][0-9]*/?\\z~'],
            'project_update_task_reference' => ['nullable', 'string', 'max:80'],
            'project_update_documentation' => ['nullable', 'string', 'max:5000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:1000'],
            'publish_in_feed' => ['nullable', 'boolean'],
            'publish_in_telegram' => ['nullable', 'boolean'],
            'publish_in_vk' => ['nullable', 'boolean'],
            'telegram_chat_ids' => ['nullable', 'array'],
            'telegram_chat_ids.*' => [
                'integer',
                Rule::exists('telegram_chats', 'id')->where('is_active', true),
            ],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    public function messages(): array
    {
        return [
            'alias.regex' => 'Используйте строчные латинские буквы и цифры, разделяя слова дефисом.',
            'alias.not_in' => 'Этот алиас зарезервирован.',
            'alias.unique' => 'Этот алиас уже занят.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = ContentTypeEnum::tryFrom((string) $this->input('type'));

                $content = $this->route('contentItem');
                if ($content?->system_key && $type !== ContentTypeEnum::FAQ) {
                    $validator->errors()->add('type', 'Системная инструкция должна оставаться материалом FAQ.');
                }

                if ($content?->type === ContentTypeEnum::SYSTEM_UPDATE
                    && $type !== ContentTypeEnum::SYSTEM_UPDATE) {
                    $validator->errors()->add('type', 'Системное обновление нельзя преобразовать в другой тип материала.');
                }

                if ($type === ContentTypeEnum::SYSTEM_UPDATE) {
                    $newPr = trim((string) $this->input('project_update_pr_url'));
                    $newDocs = preg_split('/\\R/u', (string) $this->input('project_update_documentation', '')) ?: [];
                    $newDocs = array_values(array_unique(array_filter(array_map('trim', $newDocs))));
                    foreach ($newDocs as $document) {
                        if (! preg_match('~\\Adocs/(?!.*\\.\\./)[a-zA-Z0-9_./-]+\\.md\\z~', $document)) {
                            $validator->errors()->add('project_update_documentation', 'Документы указываются путями docs/.../*.md, по одному в строке.');
                            break;
                        }
                    }

                    foreach (['title', 'short_description', 'full_description', 'meta_title', 'meta_description', 'meta_keywords'] as $publicField) {
                        if (preg_match('~github\\.com/|docs/[a-zA-Z0-9_./-]+\\.md~i', (string) $this->input($publicField))) {
                            $validator->errors()->add($publicField, 'Технические ссылки должны быть только в закрытых полях обновления.');
                        }
                    }
                    if ($this->filled('link_url')) {
                        $validator->errors()->add('link_url', 'Системные обновления не допускают отдельной технической ссылки действия.');
                    }

                    $detail = $content?->exists
                        ? ProjectUpdateDetail::query()->where('content_item_id', $content->id)->first()
                        : null;
                    $published = $this->input('status', $content?->status?->value ?? 'draft') === 'published';
                    if ($published && (blank($newPr) || count($newDocs) === 0
                        || $detail?->production_verified_at === null || $detail->pr_url !== $newPr
                        || ($detail->documentation_paths ?? []) !== $newDocs)) {
                        $validator->errors()->add('status', 'Сначала проверь merged PR, документацию и production-деплой через подтверждение в админке.');
                    }
                }

                if ($type?->requiresRelatedEntity() && ! $this->filled('related_id')) {
                    $validator->errors()->add('related_id', 'Выберите связанную сущность.');
                }

                $link = trim((string) $this->input('link_url'));
                if ($link !== '' && ! str_starts_with($link, '/') && filter_var($link, FILTER_VALIDATE_URL) === false) {
                    $validator->errors()->add('link_url', 'Укажите внутренний путь или полный URL.');
                }

                if ($type !== ContentTypeEnum::FAQ && $this->boolean('publish_in_vk')) {
                    if (! \App\Modules\Vk\Application\Services\VkWallPublisher::wallConfigured()) {
                        $validator->errors()->add('publish_in_vk', 'Публикация во ВКонтакте не настроена.');
                    } elseif ($this->input('status', $content?->status?->value ?? 'published') !== 'published' || ! $this->boolean('publish_in_feed')) {
                        $validator->errors()->add('publish_in_vk', 'Для ВКонтакте необходимо опубликовать материал в ленте сайта.');
                    }
                }

                if ($type !== ContentTypeEnum::FAQ
                    && $this->boolean('publish_in_telegram')
                    && count((array) $this->input('telegram_chat_ids', [])) === 0) {
                    $validator->errors()->add('telegram_chat_ids', 'Выберите хотя бы один Telegram-чат.');
                }
            },
        ];
    }
}
