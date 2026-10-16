<?php

namespace App\Modules\Content\Application\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Only typed data can configure the trusted hero component.
 * No HTML, JavaScript, CSS selectors or external media URLs from the editor.
 */
final class HeroSliderSettings
{
    public const MAX_SLIDES = 6;

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
        Validator::make(
            ['slides' => $slides, 'options' => $options ?? []],
            [
                'slides' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_SLIDES],
                'slides.*.eyebrow' => ['nullable', 'string', 'max:90'],
                'slides.*.title' => ['required', 'string', 'max:130'],
                'slides.*.accent_title' => ['nullable', 'string', 'max:90'],
                'slides.*.description' => ['nullable', 'string', 'max:360'],
                'slides.*.cta_label' => ['required', 'string', 'max:45'],
                'slides.*.cta_url' => ['required', 'string', 'max:220', self::internalLink()],
                'slides.*.background_desktop' => ['nullable', 'string', self::imagePath()],
                'slides.*.background_mobile' => ['nullable', 'string', self::imagePath()],
                'slides.*.far_player' => ['nullable', 'string', self::imagePath(false)],
                'slides.*.near_player' => ['nullable', 'string', self::imagePath(false)],
                'slides.*.animation_far' => ['nullable', Rule::in(['fade', 'slide-up', 'slide-left', 'zoom'])],
                'slides.*.animation_near' => ['nullable', Rule::in(['fade', 'slide-up', 'slide-left', 'zoom'])],
                'slides.*.animation_copy' => ['nullable', Rule::in(['fade', 'slide-up', 'slide-left', 'zoom'])],
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
            ],
            [
                'slides.required' => 'Добавь хотя бы один слайд.',
                'slides.max' => 'Для производительности разрешено не более шести слайдов.',
                'slides.*.cta_url.regex' => 'Разрешены только внутренние ссылки и якоря.',
            ],
        )->validate();
    }

    private static function internalLink(): string
    {
        return '~\A(?:/(?!/)[A-Za-z0-9/_#.?=&%-]*|#[A-Za-z][A-Za-z0-9_-]*)\z~D';
    }

    private static function imagePath(bool $allowFallback = true): string
    {
        $prefix = $allowFallback ? '(?:site:bg-indoor|page-builder/hero/[A-Za-z0-9_-]+\.(?:png|jpg|jpeg|webp|avif))'
            : '(?:page-builder/hero/[A-Za-z0-9_-]+\.(?:png|jpg|jpeg|webp|avif))';

        return '~\A'.$prefix.'\z~iD';
    }
}
