<?php

namespace App\Modules\Media\Domain\Traits;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasEntityBanner
{
    public function banner(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', 'banner')
            ->where('is_featured', true);
    }

    public function bannerUrl(): ?string
    {
        return $this->banner?->publicUrl();
    }
}
