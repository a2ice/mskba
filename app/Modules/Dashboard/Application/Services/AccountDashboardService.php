<?php
namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;

/** Layout-only audience rules; widget visibility never grants domain permissions. */
final class AccountDashboardService
{
    public const KINDS = [
        'account_status' => 'Статус аккаунта',
        'getting_started' => 'Возможности портала',
        'message_summary' => 'Сообщения',
        'information' => 'Информационная карточка',
        'advertisement' => 'Рекламная карточка',
        'custom_html' => 'HTML / CSS / JS (песочница)',
    ];

    /** @return Collection<int, AccountDashboardWidget> */
    public function forUser(User $user): Collection
    {
        $user = $user->canonical();
        $roles = $user->participationRoles()->pluck('role')
            ->map(fn ($role) => (string) ($role->value ?? $role))->all();
        $hasVerifiedContact = $user->hasVerifiedPrimaryContact();
        $identityIds = $user->identityIds();

        return AccountDashboardWidget::query()->where('enabled', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(function (AccountDashboardWidget $widget) use ($user, $roles, $hasVerifiedContact, $identityIds): bool {
                if (array_intersect($identityIds, $widget->exclude_user_ids ?? []) !== []) return false;
                if (array_intersect($identityIds, $widget->include_user_ids ?? []) !== []) return true;
                if (($widget->account_statuses ?? []) !== []
                    && ! in_array($user->status->value, $widget->account_statuses, true)) return false;
                if (($widget->system_roles ?? []) !== []
                    && ! in_array($user->system_role->value, $widget->system_roles, true)) return false;
                if (($widget->participation_roles ?? []) !== []
                    && array_intersect($roles, $widget->participation_roles) === []) return false;
                return match ($widget->contact_filter) {
                    'verified' => $hasVerifiedContact, 'unverified' => ! $hasVerifiedContact,
                    default => true,
                };
            })->values();
    }
}
