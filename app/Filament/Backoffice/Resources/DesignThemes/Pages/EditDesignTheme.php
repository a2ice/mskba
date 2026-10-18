<?php

namespace App\Filament\Backoffice\Resources\DesignThemes\Pages;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDesignTheme extends EditRecord
{
    protected static string $resource = DesignThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uiKit')->label('UI Kit / Гайдлайн')->icon('heroicon-o-swatch')
                ->url(fn (): string => route('design-themes.ui-kit', $this->record))
                ->openUrlInNewTab(),
            DeleteAction::make()->visible(fn () => DesignThemeResource::canDelete($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        DesignThemeResource::validateTheme(array_replace(
            $this->record->only(['colors', 'css_code']), $data,
        ));

        return $data;
    }
}
