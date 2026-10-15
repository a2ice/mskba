<?php

namespace App\Presentation\Theming;

use Illuminate\Database\Eloquent\Model;

final class DesignThemeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['colors' => 'array', 'is_published' => 'boolean', 'created_at' => 'datetime'];
    }
}
