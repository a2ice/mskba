<?php

namespace App\Modules\Dashboard\Domain\Models;

use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardChangedBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AccountDashboardWidget extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $broadcast = function (): void {
            try {
                DB::afterCommit(fn () => DashboardChangedBroadcast::dispatch());
            } catch (\Throwable $exception) {
                report($exception);
            }
        };
        self::saved($broadcast);
        self::deleted($broadcast);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'can_hide' => 'boolean',
            'sort_order' => 'integer', 'iframe_height' => 'integer',
            'image_gap_px' => 'integer', 'image_width_percent' => 'integer',
            'refresh_delay_seconds' => 'integer', 'last_edit_at' => 'datetime',
            'account_statuses' => 'array', 'system_roles' => 'array',
            'participation_roles' => 'array', 'include_user_ids' => 'array',
            'exclude_user_ids' => 'array', 'actions' => 'array',
        ];
    }
}
