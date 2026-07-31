<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'created_by_user_id',
    'updated_by_user_id',
    'type',
    'title',
    'alias',
    'short_description',
    'full_description',
    'content_format',
    'link_url',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'related_type',
    'related_id',
    'publish_in_feed',
    'publish_in_telegram',
    'feed_published_at',
])]
final class ContentItem extends Model
{
    use Auditable, SoftDeletes;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function cover(): MorphMany
    {
        return $this->media()->where('collection', 'content_cover')->orderByDesc('is_featured');
    }

    public function telegramPublications(): HasMany
    {
        return $this->hasMany(TelegramContentPublication::class);
    }

    /** @param Builder<ContentItem> $query */
    public function scopePublishedInFeed(Builder $query): Builder
    {
        return $query
            ->where('publish_in_feed', true)
            ->whereNotNull('feed_published_at')
            ->where('feed_published_at', '<=', now());
    }

    public function publicUrl(): string
    {
        return route('news.show', $this->alias);
    }

    public function destinationUrl(): string
    {
        if (! filled($this->link_url)) {
            return $this->publicUrl();
        }

        return Str::startsWith($this->link_url, ['http://', 'https://'])
            ? $this->link_url
            : url($this->link_url);
    }

    protected function casts(): array
    {
        return [
            'type' => ContentTypeEnum::class,
            'content_format' => ContentFormatEnum::class,
            'publish_in_feed' => 'boolean',
            'publish_in_telegram' => 'boolean',
            'feed_published_at' => 'immutable_datetime',
        ];
    }
}
