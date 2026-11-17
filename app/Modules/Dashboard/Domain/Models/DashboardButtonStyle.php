<?php

namespace App\Modules\Dashboard\Domain\Models;

use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardChangedBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DashboardButtonStyle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            try {
                DB::afterCommit(fn () => DashboardChangedBroadcast::dispatch());
            } catch (\Throwable $exception) {
                report($exception);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
