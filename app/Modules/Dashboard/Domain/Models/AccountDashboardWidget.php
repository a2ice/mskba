<?php
namespace App\Modules\Dashboard\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class AccountDashboardWidget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'can_hide' => 'boolean',
            'sort_order' => 'integer', 'iframe_height' => 'integer',
            'account_statuses' => 'array', 'system_roles' => 'array',
            'participation_roles' => 'array', 'include_user_ids' => 'array',
            'exclude_user_ids' => 'array',
        ];
    }
}
