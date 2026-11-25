<?php

namespace App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use App\Modules\Content\Application\Services\VenueCatalogLandingEditor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateVenueCatalogLanding extends CreateRecord
{
    protected static string $resource = VenueCatalogLandingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(VenueCatalogLandingEditor::class)->save($data);
    }
}
