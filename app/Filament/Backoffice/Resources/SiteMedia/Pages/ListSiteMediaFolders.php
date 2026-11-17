<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSiteMediaFolders extends ListRecords
{
    protected static string $resource = SiteMediaFolderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать папку')];
    }
}
