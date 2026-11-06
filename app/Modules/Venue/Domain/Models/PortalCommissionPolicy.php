<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['entity_type', 'action_key', 'mode', 'value', 'currency', 'version', 'updated_by_user_id'])]
final class PortalCommissionPolicy extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['value' => 'integer', 'version' => 'integer'];
    }
}
