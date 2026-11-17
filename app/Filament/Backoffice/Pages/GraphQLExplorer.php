<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Filament\Pages\Page;

final class GraphQLExplorer extends Page
{
    protected static ?string $slug = 'graphql-explorer';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Разработка';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'GraphQL Explorer';

    protected string $view = 'filament.backoffice.pages.graphql-explorer';

    public function getTitle(): string
    {
        return 'GraphQL Explorer';
    }

    public static function canAccess(): bool
    {
        // IDE is a development utility, not an endpoint intended for production.
        if (! app()->environment(['local', 'staging', 'testing'])) {
            return false;
        }

        $user = auth('web')->user();
        if ($user === null) {
            return false;
        }

        $permissions = app(BackofficePermissionManager::class);

        return $permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $permissions->allows($user, Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW);
    }
}
