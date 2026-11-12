<?php
namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidgetPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function availableForUser(User $user): Collection
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
    /** @return Collection<int, AccountDashboardWidgetPreference> */
    public function preferencesForUser(User $user): Collection
    {
        return AccountDashboardWidgetPreference::query()
            ->where('user_id', $user->canonical()->getKey())
            ->get()->keyBy('widget_id');
    }

    /** @return Collection<int, AccountDashboardWidget> */
    public function forUser(User $user): Collection
    {
        $prefs = $this->preferencesForUser($user);

        return $this->availableForUser($user)
            ->filter(static function (AccountDashboardWidget $widget) use ($prefs): bool {
                $preference = $prefs->get($widget->id);
                return ! $widget->can_hide || $preference === null || $preference->is_visible;
            })
            ->sort(static function (AccountDashboardWidget $a, AccountDashboardWidget $b) use ($prefs): int {
                $aOrder = $prefs->get($a->id)?->sort_order ?? PHP_INT_MAX;
                $bOrder = $prefs->get($b->id)?->sort_order ?? PHP_INT_MAX;
                return ($aOrder <=> $bOrder)
                    ?: ($a->sort_order <=> $b->sort_order)
                    ?: ($a->id <=> $b->id);
            })->values();
    }

    /** @param list<int> $requestedIds */
    public function saveVisibility(User $user, array $requestedIds): void
    {
        $user = $user->canonical();
        $available = $this->availableForUser($user);
        $allowed = $available->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if (array_diff($requestedIds, $allowed) !== []) {
            throw ValidationException::withMessages([
                'visible_widget_ids' => 'Список виджетов изменился. Обнови страницу и повтори сохранение.',
            ]);
        }

        $selected = array_fill_keys($requestedIds, true);
        $now = now();
        $rows = $available->map(static fn (AccountDashboardWidget $widget): array => [
            'user_id' => $user->getKey(),
            'widget_id' => $widget->getKey(),
            'is_visible' => ! $widget->can_hide || isset($selected[$widget->getKey()]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            // Upsert modifies visibility only, preserving drag-and-drop order.
            DB::table('account_dashboard_widget_preferences')->upsert(
                $rows, ['user_id', 'widget_id'], ['is_visible', 'updated_at']
            );
        }
    }

    /** @param list<int> $orderedIds */
    public function saveOrder(User $user, array $orderedIds): void
    {
        $user = $user->canonical();
        $visibleIds = $this->forUser($user)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        $sorted = $orderedIds;
        sort($sorted);
        sort($visibleIds);

        if (count($orderedIds) !== count(array_unique($orderedIds)) || $sorted !== $visibleIds) {
            throw ValidationException::withMessages([
                'widget_ids' => 'Список виджетов изменился. Обнови страницу и повтори сохранение.',
            ]);
        }

        // can_move protects the widget's actual grid slot, not merely its
        // existence in the submitted list. Movable widgets can exchange only
        // the remaining slots; even a forged HTTP request cannot shift a
        // locked widget indirectly by moving another one across it.
        foreach ($this->forUser($user) as $index => $widget) {
            if (! $widget->can_move && $orderedIds[$index] !== (int) $widget->getKey()) {
                throw ValidationException::withMessages([
                    'widget_ids' => 'Нельзя менять положение виджета, закреплённого администратором.',
                ]);
            }
        }

        $now = now();
        $rows = [];
        foreach ($orderedIds as $index => $id) {
            $rows[] = [
                'user_id' => $user->getKey(), 'widget_id' => $id,
                'is_visible' => true, 'sort_order' => $index,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            // Order requests may never make a hidden or foreign widget visible.
            DB::table('account_dashboard_widget_preferences')->upsert(
                $rows, ['user_id', 'widget_id'], ['sort_order', 'updated_at']
            );
        }
    }

}
