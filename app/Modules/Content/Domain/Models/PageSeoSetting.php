<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'entity_type',
    'entity_id',
    'meta_title',
    'meta_description',
    'meta_keywords',
])]
final class PageSeoSetting extends Model
{
    protected function casts(): array
    {
        return [
            'entity_type' => SeoEntityTypeEnum::class,
        ];
    }
}
