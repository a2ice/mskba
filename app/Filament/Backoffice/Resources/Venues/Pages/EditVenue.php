<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationSourcesRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationAuditRelationManager;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Livewire as LivewireSchema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Venue $venue */
        $venue = $this->getRecord();

        return array_replace($data, app(VenueBackofficeEditor::class)->fields($venue));
    }

    /**
     * Show errors for other tabs as well, rather than making Create/Save
     * appear to do nothing when the invalid field is not currently visible.
     */
    protected function onValidationError(\Illuminate\Validation\ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first();

        \Filament\Notifications\Notification::make()
            ->warning()
            ->title('Не удалось сохранить площадку')
            ->body(is_string($message) && $message !== '' ? $message : 'Проверьте поля на всех вкладках.')
            ->persistent()
            ->send();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Venue $record */
        try {
            return app(VenueBackofficeEditor::class)->save($data, $record);
        } catch (ValidationException $exception) {
            // Domain-level validation runs after Filament's form validation.
            // Convert it to the same actionable UI error as CreateVenue.
            $this->onValidationError($exception);

            throw $exception;
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Native Filament relation groups usually stack their relation managers.
     * Our venue editor already combines relation-manager tabs with the form,
     * so the three integration managers are nested inside ONE top-level tab.
     *
     * Reuse their existing Livewire components, permissions and table actions;
     * don't create duplicate forms or a new backoffice controller.
     */
    #[Unexposed]
    public function getRelationManagerTabComponent(string | int $key): ?Tab
    {
        $group = $this->getCachedRelationManagers()[$key] ?? null;

        if (! ($group instanceof RelationGroup) || ($group->getLabel() !== 'Интеграции')) {
            return parent::getRelationManagerTabComponent($key);
        }

        $group->ownerRecord($this->getRecord())->pageClass(static::class);

        return $group->getTabComponent()
            ->schema(fn (): array => [
                Tabs::make('Разделы интеграции')
                    ->key('venueIntegrationSections')
                    ->persistTabInQueryString('integration-section')
                    ->tabs(collect($group->getManagers())->map(function (string $manager): Tab {
                        [$label, $icon] = match ($manager) {
                            VenueIntegrationsRelationManager::class => ['Настройки', 'heroicon-o-cog-6-tooth'],
                            VenueIntegrationSourcesRelationManager::class => ['Источники и залы', 'heroicon-o-circle-stack'],
                            VenueIntegrationAuditRelationManager::class => ['Журнал изменений', 'heroicon-o-clock'],
                            default => [$manager::getTitle($this->getRecord(), static::class), 'heroicon-o-list-bullet'],
                        };

                        return Tab::make($label)
                            ->icon($icon)
                            ->schema([
                                LivewireSchema::make($manager, [
                                    ...$this->getRelationManagerLivewireData(),
                                    ...$manager::getDefaultProperties(),
                                ])->key('venue-integration-manager-'.class_basename($manager)),
                            ]);
                    })->values()->all()),
            ]);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }
}
