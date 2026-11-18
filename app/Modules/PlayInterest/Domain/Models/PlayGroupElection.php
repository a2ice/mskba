<?php

namespace App\Modules\PlayInterest\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PlayGroupElection extends Model
{
    protected $table = 'play_group_elections';
    protected $fillable = [
        'opportunity_id','status','electorate_user_ids','electorate_count',
        'votes_required','previous_admin_user_id','winner_user_id',
        'earliest_close_at','closes_at','finished_at',
    ];

    protected function casts(): array
    {
        return [
            'electorate_user_ids'=>'array','electorate_count'=>'integer','votes_required'=>'integer',
            'earliest_close_at'=>'immutable_datetime','closes_at'=>'immutable_datetime',
            'finished_at'=>'immutable_datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlayOpportunity::class,'opportunity_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(PlayGroupCandidate::class,'election_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PlayGroupVote::class,'election_id');
    }
}
