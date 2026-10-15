<?php

namespace App\Filament\Backoffice\Resources\DesignThemes\Pages;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDesignThemes extends ListRecords
{
    protected static string $resource = DesignThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новая тема')];
    }
}
