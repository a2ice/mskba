<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlayGroupAssembly extends Model
{
    protected $table = 'play_group_assemblies';
    protected $fillable = [
        'opportunity_id','created_by_user_id','coordination_session_id','event_id','status',
    ];
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PlayOpportunity::class,'opportunity_id');
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class,'event_id');
    }
    public function coordinationSession(): BelongsTo
    {
        return $this->belongsTo(CoordinationSession::class,'coordination_session_id');
    }
}
