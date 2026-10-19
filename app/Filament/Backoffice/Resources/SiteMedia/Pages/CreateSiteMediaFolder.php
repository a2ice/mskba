<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSiteMediaFolder extends CreateRecord
{
    protected static string $resource = SiteMediaFolderResource::class;
}
