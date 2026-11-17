<?php

namespace App\Filament\Backoffice\Resources\SystemRoleProfiles\Pages;

use App\Filament\Backoffice\Resources\SystemRoleProfiles\SystemRoleProfileResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Filament\Resources\Pages\EditRecord;

final class EditSystemRoleProfile extends EditRecord
{
    protected static string $resource = SystemRoleProfileResource::class;

    public function getTitle(): string
    {
        return 'Настройка прав роли «'.$this->roleLabel().'»';
    }

    public function getBreadcrumb(): string
    {
        return $this->roleLabel();
    }

    private function roleLabel(): string
    {
        return UserSystemRoleEnum::from($this->getRecord()->role)->label();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        SystemRoleProfileResource::validatePermissions($this->getRecord(), $data);
        unset($data['role']);

        return $data;
    }
}
