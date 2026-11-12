<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;

/**
 * Landing destination for signed-in users visiting '/'.
 * This is a fixed route allowlist, never a user-controlled URL.
 */
final class HomepagePreference
{
    public const DEFAULT = 'account';

    /** @var array<string, string> */
    public const OPTIONS = [
        'current' => 'Текущая главная (без перенаправления)',
        'account' => 'Личный кабинет',
        'venues' => 'Площадки',
        'events' => 'Мероприятия',
        'news' => 'Новости',
        'shop' => 'Магазин',
    ];

    private const ROUTES = [
        'account' => 'account',
        'venues' => 'venues',
        'events' => 'events.index',
        'news' => 'news.index',
        'shop' => 'shop.index',
    ];

    public function selected(User $user): string
    {
        $stored = $user->canonical()->homepage_preference;

        return is_string($stored) && array_key_exists($stored, self::OPTIONS)
            ? $stored
            : self::DEFAULT;
    }

    /** Relative internal URL, or null to render the real homepage. */
    public function destination(User $user): ?string
    {
        $selected = $this->selected($user);

        return isset(self::ROUTES[$selected])
            ? route(self::ROUTES[$selected], absolute: false)
            : null;
    }
}
