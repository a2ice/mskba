<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use Illuminate\Validation\ValidationException;

/** Server-side editor cooldown: independent of browser controls and tabs. */
final class DashboardWidgetSaveCooldown
{
    public function remaining(AccountDashboardWidget $widget): int
    {
        $seconds = min(60, max(0, (int) ($widget->refresh_delay_seconds ?? 5)));
        if ($seconds === 0 || $widget->last_edit_at === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInRealSeconds(
            $widget->last_edit_at->copy()->addSeconds($seconds), false
        )));
    }

    public function assertReady(AccountDashboardWidget $widget): void
    {
        if (($remaining = $this->remaining($widget)) > 0) {
            throw ValidationException::withMessages([
                'data.refresh_delay_seconds' => "Повторное сохранение доступно через {$remaining} с.",
            ]);
        }
    }
}
