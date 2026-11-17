<?php

namespace App\Filament\Backoffice\Resources\Contracts\Pages;

use App\Filament\Backoffice\Resources\Contracts\ContractResource;
use Filament\Resources\Pages\ListRecords;

final class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;
}
