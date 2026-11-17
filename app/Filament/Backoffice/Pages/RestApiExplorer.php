<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Filament\Pages\Page;

final class RestApiExplorer extends Page
{
    protected static ?string $slug = 'rest-api-explorer';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Разработка';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'REST API · Swagger';

    protected string $view = 'filament.backoffice.pages.rest-api-explorer';

    public function getTitle(): string
    {
        return 'REST API · Swagger UI';
    }

    public static function canAccess(): bool
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            return false;
        }

        $user = auth('web')->user();
        if ($user === null) {
            return false;
        }

        $permissions = app(BackofficePermissionManager::class);

        return $permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $permissions->allows($user, Permission::BACKOFFICE_REST_API_EXPLORER_VIEW);
    }
}
