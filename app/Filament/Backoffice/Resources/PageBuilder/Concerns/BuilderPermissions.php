<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Database\Eloquent\Model;

trait BuilderPermissions
{
    protected static function allowed(Permission $permission): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool
    {
        return static::allowed(Permission::BACKOFFICE_BUILDER_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::allowed(Permission::BACKOFFICE_BUILDER_EDIT);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canPublish(): bool
    {
        return static::allowed(Permission::BACKOFFICE_BUILDER_PUBLISH);
    }
}
