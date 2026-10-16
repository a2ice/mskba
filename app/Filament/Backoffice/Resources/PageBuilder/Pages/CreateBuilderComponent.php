<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBuilderComponent extends CreateRecord
{
    protected static string $resource = BuilderComponentResource::class;
}
