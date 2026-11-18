<?php

namespace App\Filament\Backoffice\Resources\PlayOpportunities\Pages;

use App\Filament\Backoffice\Resources\PlayOpportunities\PlayOpportunityResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\PlayGroupGovernance;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditPlayOpportunity extends EditRecord
{
    protected static string $resource = PlayOpportunityResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof PlayOpportunity && PlayOpportunityResource::canEdit($record), 403);
        $targetId = (int)($data['admin_user_id'] ?? 0) ?: null;
        $target = $targetId === null ? null : User::query()->findOrFail($targetId);
        $name = trim((string)($data['custom_title'] ?? ''));
        $name = $name === '' ? null : $name;
        if ($record->admin_user_id === $targetId && $record->custom_title === $name) {
            return $record;
        }
        $reason = trim((string)($data['override_reason'] ?? ''));
        if (mb_strlen($reason) < 20) {
            throw ValidationException::withMessages([
                'data.override_reason' => 'Укажите обоснование не менее 20 символов.',
            ]);
        }

        return app(PlayGroupGovernance::class)->staffOverride(
            $record, auth('web')->user(), $target, $name, $reason
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewGroup')->label('Открыть группу на сайте')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn(): string => route('play.opportunities.show',$this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }
}
