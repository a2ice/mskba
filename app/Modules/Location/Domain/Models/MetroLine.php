<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Location\Infrastructure\Database\Factories\MetroLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'color', 'sort_order'])]
class MetroLine extends Model
{
    /** @use HasFactory<MetroLineFactory> */
    use HasFactory;

    protected static function newFactory(): MetroLineFactory
    {
        return MetroLineFactory::new();
    }

    public function stations(): HasMany
    {
        return $this->hasMany(MetroStation::class);
    }
}
