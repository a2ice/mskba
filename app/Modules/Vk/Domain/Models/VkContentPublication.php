<?php

namespace App\Modules\Vk\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class VkContentPublication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'requires_cover' => 'boolean', 'group_id' => 'integer', 'post_id' => 'integer', 'published_at' => 'datetime'];
    }
}
