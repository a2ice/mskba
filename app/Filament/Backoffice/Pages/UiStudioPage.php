<?php

namespace App\Filament\Backoffice\Pages;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use App\Presentation\Theming\DesignTheme;
use Filament\Pages\Page;

final class UiStudioPage extends Page
{
    protected static ?string $slug = 'ui-studio';
    protected static ?string $navigationLabel = 'UI Studio';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт и дизайн';
    protected static ?int $navigationSort = 5;
    protected string $view = 'filament.backoffice.pages.ui-studio';

    public static function canAccess(): bool
    {
        return DesignThemeResource::canViewAny();
    }

    public function getTitle(): string
    {
        return 'UI Studio · интерфейсы и UI Kit';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $theme = DesignTheme::query()->orderBy('id')->first();

        return [
            'studioUrl' => 'https://'.config('ui-studio.host').'/uikit/bridge',
            'studioOrigin' => 'https://'.config('ui-studio.host'),
            'kitUrl' => $theme ? route('design-themes.ui-kit', $theme) : null,
            'bridgeEnabled' => (bool) config('ui-studio.issuer_enabled'),
        ];
    }
}
