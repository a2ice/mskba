<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'user_id', 'proposed_by_user_id', 'status', 'mode', 'value',
    'currency', 'proposed_permissions', 'terms', 'terms_hash', 'proposed_at', 'accepted_at',
    'accepted_by_user_id', 'closed_at', 'closed_by_user_id', 'closure_reason',
    'contract_id', 'commission_agent_id', 'reviewed_by_user_id', 'reviewed_at',
    'evidence_reference', 'review_reason', 'activated_by_user_id', 'activated_at', 'active_marker'])]
final class VenueAgentAgreement extends Model
{
    use Auditable;

    public const CONTRACT_MARKER = 'venue-agent-agreement:v1';

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function commissionAgent(): BelongsTo
    {
        return $this->belongsTo(VenueCommissionAgent::class);
    }

    protected function casts(): array
    {
        return ['value' => 'integer', 'proposed_permissions' => 'array',
            'proposed_at' => 'datetime', 'accepted_at' => 'datetime', 'closed_at' => 'datetime',
            'reviewed_at' => 'datetime', 'activated_at' => 'datetime', 'active_marker' => 'boolean'];
    }

    /** The exact terms shown to the recipient, independent of mutable presets. */
    public function calculatedTermsHash(): string
    {
        return hash('sha256', json_encode([
            'venue_id' => (int) $this->venue_id,
            'user_id' => (int) $this->user_id,
            'mode' => $this->mode,
            'value' => $this->value,
            'currency' => $this->currency,
            'permissions' => $this->proposed_permissions,
            'terms' => $this->terms,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
