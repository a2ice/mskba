<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayGroupVote extends Model
{
    protected $table = 'play_group_votes';
    protected $fillable = ['election_id','user_id','candidate_id'];

    public function election(): BelongsTo
    {
        return $this->belongsTo(PlayGroupElection::class,'election_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class,'user_id');
    }
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(PlayGroupCandidate::class,'candidate_id');
    }
}
