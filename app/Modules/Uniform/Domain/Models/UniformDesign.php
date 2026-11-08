<?php

namespace App\Modules\Uniform\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'name', 'primary_color', 'secondary_color', 'sizes', 'sleeve_style', 'logo_source', 'logo_team_id', 'jersey_number', 'number_color', 'number_outline', 'number_outline_color', 'logo_front_position', 'logo_back_position', 'editor_version'])]
final class UniformDesign extends Model
{
    use SoftDeletes;

    public const LOGO_COLLECTION = 'uniform_design_logo';

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logoTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'logo_team_id');
    }

    public function customLogo(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', self::LOGO_COLLECTION)
            ->where('is_featured', true);
    }

    protected function casts(): array
    {
        return [
            'sizes' => 'array',
            'jersey_number' => 'integer',
            'number_outline' => 'boolean',
            'editor_version' => 'integer',
        ];
    }
}
