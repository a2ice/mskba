<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Portal\Application\Services\PortalContactSettings;
use Filament\Pages\Page;

final class PortalContactSettingsPage extends Page
{
    protected static ?string $slug = 'portal-contacts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static string|\UnitEnum|null $navigationGroup = 'Сайт и дизайн';

    protected static ?string $navigationLabel = 'Контакты портала';

    protected string $view = 'filament.backoffice.pages.portal-contacts';

    public static function canAccess(): bool
    {
        return app(PortalContactSettings::class)->canManage(auth('web')->user());
    }

    public function getTitle(): string
    {
        return 'Контакты портала';
    }

    protected function getViewData(): array
    {
        return ['contacts' => app(PortalContactSettings::class)->adminValues(auth('web')->user())];
    }
}
