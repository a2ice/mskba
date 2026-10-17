<?php
namespace App\Filament\Backoffice\Resources\SystemRoleProfiles\Pages;
use App\Filament\Backoffice\Resources\SystemRoleProfiles\SystemRoleProfileResource;
use Filament\Resources\Pages\EditRecord;
final class EditSystemRoleProfile extends EditRecord {
    protected static string $resource = SystemRoleProfileResource::class;
    protected function mutateFormDataBeforeSave(array $data): array {
        SystemRoleProfileResource::validatePermissions($this->getRecord(), $data);
        unset($data['role']);
        return $data;
    }
}