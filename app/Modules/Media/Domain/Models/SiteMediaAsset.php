<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SiteMediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'folder_id', 'created_by', 'title', 'description', 'alt',
        'original_name', 'legacy_path', 'current_media_id', 'previous_media_id',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(SiteMediaFolder::class, 'folder_id');
    }

    public function currentMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'current_media_id');
    }

    public function previousMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'previous_media_id');
    }

    public function versions(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function publicUrl(): string
    {
        return route('site-media.show', ['uuid' => $this->uuid], false);
    }
}
