<?php

namespace App\Presentation\Theming;

use Illuminate\Database\Eloquent\Model;

final class DesignThemeCodeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
