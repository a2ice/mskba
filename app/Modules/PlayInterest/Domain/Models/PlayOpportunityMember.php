<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayOpportunityMember extends Model
{
    protected $table = 'play_opportunity_members';
    protected $fillable = ['opportunity_id','user_id','play_intent_ids','status','joined_at','left_at'];

    protected function casts(): array
    {
        return ['play_intent_ids'=>'array','joined_at'=>'immutable_datetime','left_at'=>'immutable_datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlayOpportunity::class, 'opportunity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
