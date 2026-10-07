<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use Illuminate\Support\Str;

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
                ?: $this->fallbackDescription($fallbackDescription, $fallbackTitle),
            'metaKeywords' => $setting?->meta_keywords,
            'canonicalUrl' => $canonicalUrl,
        ];
    }

    private function fallbackDescription(?string $description, string $fallbackTitle): string
    {
        $plain = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags((string) $description), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));

        if ($plain === '') {
            $plain = $fallbackTitle.' — информация на баскетбольном портале MSKBA.';
        }

        return Str::limit($plain, 300, '…');
    }
}
