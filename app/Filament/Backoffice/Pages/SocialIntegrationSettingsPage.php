<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;

final class SocialIntegrationSettingsPage extends Page
{
    protected static ?string $slug = 'social-integrations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static string|\UnitEnum|null $navigationGroup = 'Сайт и дизайн';

    protected static ?string $navigationLabel = 'Социальные сети';

    protected string $view = 'filament.backoffice.pages.social-integrations';

    public function getTitle(): string
    {
        return 'Социальные сети';
    }

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && $user->system_role === UserSystemRoleEnum::SUPERADMIN
            && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed();
    }
}
