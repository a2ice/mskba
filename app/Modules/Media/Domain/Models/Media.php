<?php

namespace App\Modules\Media\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Media\Infrastructure\Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'mediable_type',
    'mediable_id',
    'collection',
    'source',
    'source_reference',
    'disk',
    'path',
    'title',
    'description',
    'mime',
    'size',
    'is_featured',
    'sort_order',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use Auditable, HasFactory;

    use SoftDeletes;

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function publicUrl(): string
    {
        if ($this->disk === 'public') {
            return '/storage/'.ltrim($this->path, '/');
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
