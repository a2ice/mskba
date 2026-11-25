<?php

namespace App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use App\Modules\Content\Application\Services\VenueCatalogLandingEditor;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

final class EditVenueCatalogLanding extends EditRecord
{
    protected static string $resource = VenueCatalogLandingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Предпросмотр')
                ->url(fn (): string => route('admin.content.catalog-landings.preview', ['landingId' => $this->record->getKey()]))
                ->openUrlInNewTab(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $seo = PageSeoSetting::query()->where('entity_type', SeoEntityTypeEnum::VENUE_CATALOG_LANDING->value)
            ->where('entity_id', $this->record->getKey())->first();

        return $data + ['meta_title' => $seo?->meta_title, 'meta_description' => $seo?->meta_description];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(VenueCatalogLandingEditor::class)->save($data, $record);
    }
}
