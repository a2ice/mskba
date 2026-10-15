<?php

namespace App\Filament\Backoffice\Resources\DesignThemes\Pages;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDesignTheme extends CreateRecord
{
    protected static string $resource = DesignThemeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        DesignThemeResource::validateTheme($data);

        return $data;
    }
}
