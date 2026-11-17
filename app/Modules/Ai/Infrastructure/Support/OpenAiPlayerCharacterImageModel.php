<?php

namespace App\Modules\Ai\Infrastructure\Support;

/**
 * Player sprites require an alpha channel; GPT Image 2 transparency remains in preview.
 * Keep legacy deployment overrides from selecting a model that rejects transparent PNGs.
 */
final class OpenAiPlayerCharacterImageModel
{
    public const DEFAULT_MODEL = 'gpt-image-2.5-sunburst';

    public static function configured(): string
    {
        $model = trim((string) config('services.openai.image_model', self::DEFAULT_MODEL));

        return in_array($model, ['', 'gpt-image-1', 'gpt-image-2', 'gpt-image-2-2026-04-21'], true)
            ? self::DEFAULT_MODEL
            : $model;
    }
}
