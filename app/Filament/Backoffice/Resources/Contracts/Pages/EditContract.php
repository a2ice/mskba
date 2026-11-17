<?php

namespace App\Filament\Backoffice\Resources\Contracts\Pages;

use App\Filament\Backoffice\Resources\Contracts\ContractResource;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Domain\Models\Contract;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = $this->getRecord()->permissions()->pluck('permission')->all();
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(BackofficeContractEditor::class)->update($record, $data);
    }
}
