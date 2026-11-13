<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationSourcesRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationAuditRelationManager;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\ToggleVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Livewire as LivewireSchema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;

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
        return app(VenueBackofficeEditor::class)->save($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The venue booking policy is a separate versioned entity, not
            // a Venue attribute. Show its real state instead of a duplicate
            // toggle that could disagree with the active policy.
            Action::make('rentalPolicy')
                ->label(fn (): string => match ($this->activeBookingPolicy()?->is_enabled) {
                    true => 'Аренда: включена',
                    false => 'Аренда: выключена',
                    null => 'Аренда: не настроена',
                })
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn (): string => route('account.venues.booking-policy.edit', [
                    'venue' => $this->getRecord()->getKey(),
                ]))
                ->visible(fn (): bool => $this->canManageRentalPolicy()),
            Action::make('toggleRental')
                ->label(fn (): string => $this->activeBookingPolicy()?->is_enabled
                    ? 'Выключить аренду' : 'Включить аренду')
                ->icon('heroicon-o-power')
                ->color(fn (): string => $this->activeBookingPolicy()?->is_enabled ? 'warning' : 'success')
                ->visible(fn (): bool => $this->canManageRentalPolicy()
                    && $this->activeBookingPolicy() !== null)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->activeBookingPolicy()?->is_enabled
                    ? 'Выключить онлайн-бронирование?' : 'Включить онлайн-бронирование?')
                ->modalDescription('Будет опубликована новая версия условий аренды площадки. '
                    .'Наличие и условия уже созданных бронирований не изменятся.')
                ->action(function (): void {
                    $user = auth('web')->user();
                    abort_unless($user instanceof User && $this->canManageRentalPolicy(), 403);
                    $policy = $this->activeBookingPolicy();

                    if ($policy === null) {
                        Notification::make()->warning()
                            ->title('Сначала опубликуйте условия аренды')->send();

                        return;
                    }

                    try {
                        $updated = app(ToggleVenueBookingPolicyHandler::class)->handle(
                            $this->getRecord(), $user, ! $policy->is_enabled,
                        );
                    } catch (VenueBookingPolicyException $exception) {
                        Notification::make()->danger()
                            ->title('Не удалось изменить доступность аренды')
                            ->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title($updated->is_enabled ? 'Онлайн-бронирование включено' : 'Онлайн-бронирование выключено')
                        ->body('Опубликована версия условий №'.$updated->version.'.')->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function activeBookingPolicy(): ?VenueBookingPolicy
    {
        return VenueBookingPolicy::query()
            ->where('venue_id', $this->getRecord()->getKey())
            ->where('active_marker', true)
            ->first();
    }

    private function canManageRentalPolicy(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && app(VenueCommercialAccess::class)->allows(
                $user, $this->getRecord(), VenuePermissionEnum::MANAGE_BOOKING_POLICY,
            );
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
