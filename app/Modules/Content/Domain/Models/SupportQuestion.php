<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class SupportQuestion extends Model
{
    protected $fillable = ['user_id', 'topic', 'source_path', 'body', 'emailed_at'];

    protected function casts(): array
    {
        return ['emailed_at' => 'datetime'];
    }
}
