<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayGroupCandidate extends Model
{
    protected $table = 'play_group_candidates';
    protected $fillable = ['election_id','user_id','reason','withdrawn_at'];
    protected function casts(): array
    {
        return ['withdrawn_at'=>'immutable_datetime'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(PlayGroupElection::class,'election_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
