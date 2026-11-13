<?php

namespace App\Filament\Backoffice\Resources\Promotions\Pages;

use App\Filament\Backoffice\Resources\Promotions\PromotionResource;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

final class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['type'] ?? null) === PromotionType::MULTI_REWARDABLE->value) {
            throw ValidationException::withMessages(['data.type' => 'Несколько вознаграждений пока недоступны.']);
        }

        if (($data['type'] ?? null) === PromotionType::SIMPLE->value) {
            $data['reward_id'] = null;
        }

        unset($data['code']);

        return $data;
    }
}
