<?php

namespace App\Filament\Backoffice\Resources\Rewards\Pages;

use App\Filament\Backoffice\Resources\Rewards\RewardResource;
use App\Modules\Rewards\Application\Services\RewardCatalogManager;
use App\Modules\Rewards\Domain\Models\Reward;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditReward extends EditRecord
{
    protected static string $resource = RewardResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Reward $reward */
        $reward = $this->getRecord();
        $version = $reward->currentVersion()->firstOrFail();

        return array_replace($data, [
            'amount_rub' => number_format((int) $version->amount_minor / 100, 2, '.', ''),
            'conditions' => $version->conditions,
            'recipient_description' => $version->recipient_description,
            'trigger_description' => $version->trigger_description,
            'mechanism_parameters_json' => $version->mechanism_parameters
                ? json_encode($version->mechanism_parameters, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : '',
            'amount_caption_template' => $reward->amount_caption_template
                ?? ($reward->code === 'venue_rental_completed' ? 'Кешбэк: {amount}' : 'Бонус: {amount}'),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Reward $record */
        return app(RewardCatalogManager::class)->update($record, RewardResource::prepareData($data));
    }
}
