<?php

namespace App\Filament\Backoffice\Resources\OperatorUsers\Pages;

use App\Filament\Backoffice\Resources\OperatorUsers\OperatorUserResource;
use Filament\Resources\Pages\ListRecords;

final class ListOperatorUsers extends ListRecords
{
    protected static string $resource = OperatorUserResource::class;
}
