<?php

namespace App\Filament\Backoffice\Resources\SupportTickets\Pages;

use App\Filament\Backoffice\Resources\SupportTickets\SupportTicketResource;
use Filament\Resources\Pages\ListRecords;

final class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;
}
