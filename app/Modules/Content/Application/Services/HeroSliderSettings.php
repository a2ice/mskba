<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Versioned, data-only slides. Older MSKBA hero slides remain supported, while
 * "layers" slides contain arbitrary ordered typed elements. No raw JS or CSS.
 */
final class HeroSliderSettings
{
    public const MAX_SLIDES = 6;
    public const MAX_LAYERS = 16;
    public const MOTIONS = ['fade', 'slide-up', 'slide-left', 'zoom', 'none'];
    public const LAYER_TYPES = ['heading', 'text', 'image', 'button', 'links'];

    public static function options(?array $options): array
    {
        return [
            'autoplay' => (bool) ($options['autoplay'] ?? false),
            'interval_ms' => max(4000, min(30000, (int) ($options['interval_ms'] ?? 7000))),
            'show_arrows' => (bool) ($options['show_arrows'] ?? true),
            'show_dots' => (bool) ($options['show_dots'] ?? true),
        ];
    }

    /** @param array<mixed>|null $slides */
    public static function validate(?array $slides, ?array $options): void
    {
        $data = ['slides' => $slides, 'options' => $options ?? []];
        $validator = Validator::make($data, [
            'slides' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_SLIDES],
            'slides.*.layout' => ['nullable', Rule::in(['legacy', 'layers'])],
            'slides.*.name' => ['nullable', 'string', 'max:90'],
            'slides.*.enabled' => ['nullable', 'boolean'],
            'slides.*.audience' => ['nullable', 'array'],
            'slides.*.audience.auth' => ['nullable', Rule::in(['all', 'guest', 'authenticated'])],
            'slides.*.audience.confirmation' => ['nullable', Rule::in(['any', 'confirmed', 'unconfirmed'])],
            'slides.*.audience.system_roles' => ['nullable', 'array', 'list', 'max:6'],
            'slides.*.audience.system_roles.*' => ['string', Rule::in(array_map(
                static fn (UserSystemRoleEnum $role): string => $role->value,
                array_filter(UserSystemRoleEnum::cases(), static fn (UserSystemRoleEnum $role): bool => $role !== UserSystemRoleEnum::SYSTEM),
            ))],
            'slides.*.audience.participation_roles' => ['nullable', 'array', 'list', 'max:7'],
            'slides.*.audience.participation_roles.*' => ['string', Rule::in(array_map(
                static fn (UserParticipationRoleEnum $role): string => $role->value,
                UserParticipationRoleEnum::cases(),
            ))],
            // Reserved contract, not an active feature until user tags exist.
            'slides.*.audience.tags_any' => ['nullable', 'array', 'list', 'max:10'],
            'slides.*.audience.tags_none' => ['nullable', 'array', 'list', 'max:10'],

            'slides.*.background_effect' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.scrim' => ['nullable', Rule::in(['none', 'dark', 'light'])],
            'slides.*.background_image' => ['nullable', 'string', self::imagePath()],
            'slides.*.background_image_mobile' => ['nullable', 'string', self::imagePath()],
            'slides.*.background_desktop' => ['nullable', 'string', self::imagePath()],
            'slides.*.background_mobile' => ['nullable', 'string', self::imagePath()],
            'slides.*.animation_background' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.delay_background_ms' => ['nullable', 'integer', 'between:0,5000'],

            'slides.*.elements' => ['nullable', 'array', 'list', 'max:'.self::MAX_LAYERS],
            'slides.*.elements.*.type' => ['required', Rule::in(self::LAYER_TYPES)],
            'slides.*.elements.*.name' => ['required', 'string', 'max:90'],
            'slides.*.elements.*.class_name' => ['nullable', 'string', 'max:160',
                'regex:/\A[a-z][a-z0-9_-]{0,49}(?: [a-z][a-z0-9_-]{0,49}){0,2}\z/D'],
            'slides.*.elements.*.content' => ['nullable', 'string', 'max:400'],
            'slides.*.elements.*.url' => ['nullable', 'string', 'max:220', self::internalLink()],
            'slides.*.elements.*.image' => ['nullable', 'string', self::imagePath()],
            'slides.*.elements.*.alt' => ['nullable', 'string', 'max:140'],
            'slides.*.elements.*.show_arrow' => ['nullable', 'boolean'],
            'slides.*.elements.*.links' => ['nullable', 'array', 'list', 'max:8'],
            'slides.*.elements.*.links.*.label' => ['required', 'string', 'max:35'],
            'slides.*.elements.*.links.*.url' => ['required', 'string', 'max:220', self::internalLink()],
            'slides.*.elements.*.links.*.icon' => ['nullable', Rule::in(['court', 'trophy', 'users', 'video', 'chart', 'custom'])],
            'slides.*.elements.*.links.*.image' => ['nullable', 'string', self::imagePath()],
            'slides.*.elements.*.animation' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.elements.*.delay_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.elements.*.duration_ms' => ['nullable', 'integer', 'between:100,3000'],
            'slides.*.elements.*.x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.elements.*.y' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.elements.*.width' => ['nullable', 'numeric', 'between:5,100'],
            'slides.*.elements.*.mobile_x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.elements.*.mobile_y' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.elements.*.mobile_width' => ['nullable', 'numeric', 'between:5,100'],
            'slides.*.elements.*.z_index' => ['nullable', 'integer', 'between:2,7'],
            'slides.*.elements.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],

            // Existing slide format is intentionally kept for published content.
            'slides.*.eyebrow' => ['nullable', 'string', 'max:90'],
            'slides.*.title' => ['nullable', 'string', 'max:130'],
            'slides.*.accent_title' => ['nullable', 'string', 'max:90'],
            'slides.*.description' => ['nullable', 'string', 'max:360'],
            'slides.*.cta_label' => ['nullable', 'string', 'max:45'],
            'slides.*.cta_url' => ['nullable', 'string', 'max:220', self::internalLink()],
            'slides.*.far_player' => ['nullable', 'string', self::imagePath()],
            'slides.*.near_player' => ['nullable', 'string', self::imagePath()],
            'slides.*.animation_highlights' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.animation_cta' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.animation_far' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.animation_near' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.animation_copy' => ['nullable', Rule::in(self::MOTIONS)],
            'slides.*.delay_far_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.delay_near_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.delay_copy_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.delay_highlights_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.delay_cta_ms' => ['nullable', 'integer', 'between:0,5000'],
            'slides.*.far_x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.near_x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.far_width' => ['nullable', 'numeric', 'between:5,65'],
            'slides.*.near_width' => ['nullable', 'numeric', 'between:5,90'],
            'slides.*.far_mobile_x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.near_mobile_x' => ['nullable', 'numeric', 'between:0,100'],
            'slides.*.highlights' => ['nullable', 'array', 'list', 'max:5'],
            'slides.*.highlights.*.icon' => ['required', Rule::in(['court', 'trophy', 'users', 'video', 'chart'])],
            'slides.*.highlights.*.label' => ['required', 'string', 'max:35'],
            'slides.*.highlights.*.url' => ['required', 'string', 'max:220', self::internalLink()],

            'options' => ['array'],
            'options.autoplay' => ['nullable', 'boolean'],
            'options.interval_ms' => ['nullable', 'integer', 'between:4000,30000'],
            'options.show_arrows' => ['nullable', 'boolean'],
            'options.show_dots' => ['nullable', 'boolean'],
        ], [
            'slides.required' => 'Добавь хотя бы один слайд.',
            'slides.max' => 'Для производительности разрешено не более шести слайдов.',
        ]);

        $validator->after(static function ($validator) use ($slides): void {
            foreach (is_array($slides) ? $slides : [] as $index => $slide) {
                if (! is_array($slide)) {
                    continue;
                }
                $path = "slides.{$index}";
                $audience = is_array($slide['audience'] ?? null) ? $slide['audience'] : [];
                if (! empty($audience['tags_any']) || ! empty($audience['tags_none'])) {
                    $validator->errors()->add("{$path}.audience", 'Фильтрация по тегам станет доступна после внедрения тегов пользователей.');
                }
                if (($slide['layout'] ?? 'legacy') !== 'layers') {
                    if (trim((string) ($slide['title'] ?? '')) === '') {
                        $validator->errors()->add("{$path}.title", 'Для старого макета нужен заголовок.');
                    }
                    if (trim((string) ($slide['cta_label'] ?? '')) === '' || trim((string) ($slide['cta_url'] ?? '')) === '') {
                        $validator->errors()->add("{$path}.cta_label", 'Для старого макета нужна кнопка со ссылкой.');
                    }
                    continue;
                }
                if (! is_array($slide['elements'] ?? null) || count($slide['elements']) === 0) {
                    $validator->errors()->add("{$path}.elements", 'Добавь хотя бы один элемент слайда.');
                    continue;
                }
                foreach ($slide['elements'] as $layerIndex => $layer) {
                    if (! is_array($layer)) {
                        continue;
                    }
                    $prefix = "{$path}.elements.{$layerIndex}";
                    $type = $layer['type'] ?? '';
                    if (in_array($type, ['heading', 'text', 'button'], true)
                        && trim((string) ($layer['content'] ?? '')) === '') {
                        $validator->errors()->add("{$prefix}.content", 'Заполни текст элемента.');
                    }
                    if ($type === 'button' && trim((string) ($layer['url'] ?? '')) === '') {
                        $validator->errors()->add("{$prefix}.url", 'Кнопке нужна внутренняя ссылка.');
                    }
                    if ($type === 'image' && trim((string) ($layer['image'] ?? '')) === '') {
                        $validator->errors()->add("{$prefix}.image", 'Выбери изображение.');
                    }
                    if ($type === 'links') {
                        $links = $layer['links'] ?? null;
                        if (! is_array($links) || count($links) < 1) {
                            $validator->errors()->add("{$prefix}.links", 'Добавь хотя бы одну ссылку с иконкой.');
                        } else {
                            foreach ($links as $itemIndex => $item) {
                                if (is_array($item) && ($item['icon'] ?? null) === 'custom'
                                    && trim((string) ($item['image'] ?? '')) === '') {
                                    $validator->errors()->add("{$prefix}.links.{$itemIndex}.image", 'Загрузи иконку ссылки.');
                                }
                            }
                        }
                    }
                }
            }
        });

        $validator->validate();
    }

    private static function internalLink(): string
    {
        return 'regex:~\A(?:/(?!/)[A-Za-z0-9/_#.?=&%-]*|#[A-Za-z][A-Za-z0-9_-]*)\z~D';
    }

    private static function imagePath(): string
    {
        return 'regex:~\Apage-builder/hero/[A-Za-z0-9_-]+\.(?:png|jpg|jpeg|webp|avif)\z~iD';
    }
}
