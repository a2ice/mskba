<?php

namespace App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use App\Modules\Content\Application\Services\VenueCatalogLandingEditor;
use App\Modules\Content\Application\Services\VenueCatalogLandingSelectionAudit;
use Filament\Notifications\Notification;
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
            Action::make('inspectSelection')->label('Проверить выборку')
                ->tooltip('Проверяет сохранённые фильтры без изменения записи')
                ->action(function (): void {
                    $result = app(VenueCatalogLandingSelectionAudit::class)->inspect($this->record->fresh());
                    $count = $result['matching'];
                    $body = 'По сохранённым фильтрам найдено площадок: '.$count.'. ';
                    $body .= $count === 0
                        ? 'Не рекомендуется активировать пустую подборку.'
                        : ($count < 3 ? 'Мало площадок: проверьте наполнение перед публикацией.' : 'Базовый ориентир по наполнению достигнут; требуется редакционная проверка.');
                    if ($result['city_matching'] !== null) {
                        $body .= ' В выбранном городе по остальным условиям: '.$result['city_matching']
                            .'. Без корректной привязки к округу или району: '.$result['missing_district']
                            .'. Округов и районов в справочнике города: '.$result['district_count'].'.';
                    }
                    $body .= ' Это количество площадок, а не свободных слотов.';

                    $notification = Notification::make()->title('Проверка сохранённой подборки')->body($body)->persistent();
                    if ($count < 3 || ($result['missing_district'] ?? 0) > 0) {
                        $notification->warning();
                    } else {
                        $notification->success();
                    }
                    $notification->send();
                }),
            Action::make('preview')->label('Предпросмотр')
                ->url(fn (): string => route('admin.content.catalog-landings.preview', ['landingId' => $this->record->getKey()]))
                ->openUrlInNewTab(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $seo = PageSeoSetting::query()->where('entity_type', SeoEntityTypeEnum::VENUE_CATALOG_LANDING->value)
            ->where('entity_id', $this->record->getKey())->first();

        $data['related_landing_ids'] = $this->record->related_landing_ids ?? [];
        $data['type'] = \App\Modules\Venue\Application\Services\VenueTypeFilter::values($this->record->type);

        return $data + ['meta_title' => $seo?->meta_title, 'meta_description' => $seo?->meta_description];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(VenueCatalogLandingEditor::class)->save($data, $record);
    }
}
