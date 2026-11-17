<?php

namespace App\Filament\Backoffice\Resources\Rewards\Pages;

use App\Filament\Backoffice\Resources\Rewards\RewardResource;
use App\Modules\Rewards\Application\Services\RewardCatalogManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateReward extends CreateRecord
{
    protected static string $resource = RewardResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(RewardCatalogManager::class)->create(RewardResource::prepareData($data));
    }
}
