<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;

final class PageSeoResolver
{
    /**
     * @return array{metaTitle: string, metaDescription: string, metaKeywords: string|null, canonicalUrl: string}
     */
    public function resolve(
        SeoEntityTypeEnum $entityType,
        int $entityId,
        string $fallbackTitle,
        ?string $fallbackDescription,
        string $canonicalUrl,
    ): array {
        $setting = PageSeoSetting::query()
            ->where('entity_type', $entityType->value)
            ->where('entity_id', $entityId)
            ->first();

        return [
            'metaTitle' => $setting?->meta_title ?: $fallbackTitle,
            'metaDescription' => $setting?->meta_description
                ?: ($fallbackDescription ?: $fallbackTitle.' — MSKBA'),
            'metaKeywords' => $setting?->meta_keywords,
            'canonicalUrl' => $canonicalUrl,
        ];
    }
}
