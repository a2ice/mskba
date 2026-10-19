<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Support\Facades\Schema;

final class SiteMediaUsage
{
    /** @return list<string> */
    public function references(SiteMediaAsset $asset, bool $publishedOnly = false): array
    {
        $needles = array_filter([$asset->uuid, $asset->legacy_path]);
        $matches = [];

        foreach ([
            ['pages', PageBuilderPage::class, ['html', 'css', 'js']],
            ['templates', PageBuilderTemplate::class, ['html', 'css', 'js']],
            ['components', PageBuilderComponent::class, ['html', 'css', 'js', 'slides']],
        ] as [$type, $model, $suffixes]) {
            $query = $model::query();
            foreach ($query->get() as $record) {
                foreach ($publishedOnly ? ['published'] : ['published', 'draft'] as $state) {
                    foreach ($suffixes as $suffix) {
                        $column = $state.'_'.$suffix;
                        if (! Schema::hasColumn($record->getTable(), $column)) {
                            continue;
                        }
                        $value = $record->getAttribute($column);
                        $text = is_scalar($value) ? (string) $value : json_encode($value);
                        foreach ($needles as $needle) {
                            if (is_string($text) && str_contains($text, (string) $needle)) {
                                $matches[] = $type.' #'.$record->id.' ('.$state.')';
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($matches));
    }
}
