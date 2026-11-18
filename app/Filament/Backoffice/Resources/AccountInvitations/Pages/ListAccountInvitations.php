<?php

namespace App\Filament\Backoffice\Resources\AccountInvitations\Pages;

use App\Filament\Backoffice\Resources\AccountInvitations\AccountInvitationResource;
use Filament\Resources\Pages\ListRecords;

final class ListAccountInvitations extends ListRecords
{
    protected static string $resource = AccountInvitationResource::class;
}
