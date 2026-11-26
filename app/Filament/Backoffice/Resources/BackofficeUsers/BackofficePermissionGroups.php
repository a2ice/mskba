<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Validation\ValidationException;

/**
 * Presentation only. Permission keys, grants and role ceilings are unchanged.
 */
final class BackofficePermissionGroups
{
    public static function all(): array
    {
        $groups = [
            'general' => ['label' => 'Общие', 'permissions' => []],
            'users' => ['label' => 'Пользователи', 'permissions' => []],
            'invitations' => ['label' => 'Приглашения', 'permissions' => []],
            'venues' => ['label' => 'Площадки', 'permissions' => []],
            'bookings' => ['label' => 'Аренда и бронирование', 'permissions' => []],
            'integrations' => ['label' => 'Интеграции площадок', 'permissions' => []],
            'contracts' => ['label' => 'Контракты', 'permissions' => []],
            'sports' => ['label' => 'Спортивные сущности', 'permissions' => []],
            'design' => ['label' => 'Сайт и дизайн', 'permissions' => []],
            'media' => ['label' => 'Медиабиблиотека', 'permissions' => []],
            'api' => ['label' => 'API', 'permissions' => []],
            'other' => ['label' => 'Прочие', 'permissions' => []],
        ];
        foreach (Permission::backofficeCases() as $permission) {
            $key = $permission->value;
            $group = match (true) {
                $permission === Permission::BACKOFFICE_ACCESS => 'general',
                str_starts_with($key, 'backoffice.user.') => 'users',
                str_starts_with($key, 'backoffice.invitation.') => 'invitations',
                str_starts_with($key, 'backoffice.venue.bookings.') => 'bookings',
                str_starts_with($key, 'backoffice.venue.integrations.') => 'integrations',
                str_starts_with($key, 'backoffice.venue.') => 'venues',
                str_starts_with($key, 'backoffice.contract.') => 'contracts',
                str_starts_with($key, 'backoffice.event.'), str_starts_with($key, 'backoffice.team.'),
                str_starts_with($key, 'backoffice.league.'), str_starts_with($key, 'backoffice.season.'),
                str_starts_with($key, 'backoffice.tournament.') => 'sports',
                str_starts_with($key, 'backoffice.widget.'), str_starts_with($key, 'backoffice.theme.'),
                str_starts_with($key, 'backoffice.button_style.'), str_starts_with($key, 'backoffice.page_builder.') => 'design',
                str_starts_with($key, 'backoffice.site_media.') => 'media',
                str_starts_with($key, 'backoffice.graphql_explorer.'), str_starts_with($key, 'backoffice.rest_api_explorer.') => 'api',
                default => 'other',
            };
            $groups[$group]['permissions'][] = $permission;
        }

        return array_filter($groups, static fn (array $group): bool => $group['permissions'] !== []);
    }

    /** Preserve the existing flat snapshot contract at the domain boundary. */
    public static function split(array $keys): array
    {
        $result = [];
        foreach (self::all() as $id => $group) {
            $allowed = array_map(static fn (Permission $p): string => $p->value, $group['permissions']);
            $result[$id] = array_values(array_intersect($keys, $allowed));
        }

        return $result;
    }

    public static function flatten(array $groups): array
    {
        $keys = [];
        $catalog = self::all();
        foreach ($catalog as $id => $group) {
            $values = $groups[$id] ?? null;
            $allowed = array_map(static fn (Permission $p): string => $p->value, $group['permissions']);
            if (! is_array($values) || array_filter($values, 'is_string') !== $values
                || array_diff($values, $allowed) !== [] || count($values) !== count(array_unique($values))) {
                throw ValidationException::withMessages(['backoffice_permissions' => 'Некорректный или неполный набор разрешений. Обновите редактор.']);
            }
            array_push($keys, ...array_values($values));
        }
        if (array_diff_key($groups, $catalog) !== []) {
            throw ValidationException::withMessages(['backoffice_permissions' => 'Неизвестная группа разрешений.']);
        }

        return $keys;
    }
}
