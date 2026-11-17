<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SiteMediaFolder extends Model
{
    protected $fillable = ['name', 'slug'];

    public function assets(): HasMany
    {
        return $this->hasMany(SiteMediaAsset::class, 'folder_id');
    }
}
