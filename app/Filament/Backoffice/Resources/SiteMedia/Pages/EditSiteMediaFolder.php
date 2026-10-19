<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSiteMediaFolder extends EditRecord
{
    protected static string $resource = SiteMediaFolderResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn (): bool => SiteMediaFolderResource::canDelete($this->record))];
    }
}
