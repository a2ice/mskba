<?php

namespace App\Filament\Backoffice\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

final class Commissions extends Page
{
    protected static ?string $slug = 'commissions';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Комиссии';
    protected string $view = 'filament.backoffice.pages.commissions';

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized() && $user instanceof User
            && $user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    public function getTitle(): string
    {
        return 'Комиссии';
    }

    public function globalRate(): string
    {
        $policy = $this->currentPolicy();
        return match ($policy?->mode) {
            'percent' => number_format(($policy->value ?? 0) / 100, 2, ',', ' ').'%',
            'fixed' => app(MinorAmountParser::class)->format($policy->value, $policy->currency).' ₽',
            default => '0%',
        };
    }

    public function globalVersion(): int
    {
        return $this->currentPolicy()?->version ?? 0;
    }

    private function currentPolicy(): ?PortalCommissionPolicy
    {
        return PortalCommissionPolicy::query()->where('entity_type', 'venue')
            ->where('action_key', 'rental')->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editGlobalCommission')
                ->label('Изменить глобальную ставку')
                ->icon('heroicon-o-pencil-square')
                ->modalHeading('Глобальная комиссия · Площадки / Аренда')
                ->modalDescription('Ставка применяется к новым расчётам, если площадка и зал её не переопределяют.')
                ->modalWidth(Width::Large)
                ->schema([
                    Select::make('mode')->label('Способ расчёта')->required()
                        ->options(['percent' => 'Процент (включая 0%)', 'fixed' => 'Фиксированная сумма']),
                    TextInput::make('value_text')->label('Значение (% или руб.)')
                        ->required()->maxLength(32),
                ])
                ->fillForm(function (): array {
                    $policy = $this->currentPolicy();
                    $mode = $policy?->mode === 'fixed' ? 'fixed' : 'percent';
                    return [
                        'mode' => $mode,
                        'value_text' => $mode === 'fixed'
                            ? app(MinorAmountParser::class)->format($policy?->value ?? 0, 'RUB')
                            : number_format(($policy?->value ?? 0) / 100, 2, '.', ''),
                    ];
                })
                ->action(function (array $data): void {
                    abort_unless(self::canAccess(), 403);
                    app(VenueCommissionManager::class)->setPortal(
                        auth('web')->user(), $data['mode'], $data['value_text']
                    );
                    Notification::make()->success()->title('Глобальная комиссия сохранена')->send();
                }),
        ];
    }
}
