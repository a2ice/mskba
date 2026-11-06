<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCommissionAgent;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CommissionAgentsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionAgents';
    protected static ?string $title = 'Комиссии и агенты';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized() && ! $ownerRecord->trashed()
            && $user !== null && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function manager(): VenueCommissionManager
    {
        return app(VenueCommissionManager::class);
    }

    private function actor(): User
    {
        $user = auth('web')->user();
        abort_unless($user !== null && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN), 403);

        return $user;
    }

    /** @return array<string,string> */
    private function commissionModes(bool $inherit): array
    {
        return ($inherit ? ['inherit' => 'Наследовать'] : [])
            + ['none' => 'Без комиссии (0%)', 'percent' => 'Процент', 'fixed' => 'Фиксированная сумма'];
    }

    /** @return array<int,mixed> */
    private function rateFields(bool $inherit = false): array
    {
        return [
            Select::make('mode')->label('Способ расчёта')
                ->options($this->commissionModes($inherit))
                ->default($inherit ? 'inherit' : 'none')->required(),
            TextInput::make('value_text')->label('Значение, % или руб.')
                ->default('0')->required()->maxLength(32)
                ->helperText('Процент указывается как 10 или 5,50. Для суммы используйте рубли. Ноль допускается.'),
        ];
    }

    /** @return array{mode:string,value_text:string} */
    private function rateState(?string $mode, ?int $value, string $currency, bool $inherit): array
    {
        $mode ??= $inherit ? 'inherit' : 'none';
        $value ??= 0;

        return [
            'mode' => $mode,
            'value_text' => match ($mode) {
                'percent' => number_format($value / 100, 2, '.', ''),
                'fixed' => app(MinorAmountParser::class)->format($value, $currency),
                default => '0',
            },
        ];
    }

    private function toast(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.username')->label('Коммерческий агент')
                    ->placeholder('Пользователь')->searchable(),
                TextColumn::make('mode')->label('Тип комиссии')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'percent' => 'Процент', 'fixed' => 'Фиксированная', default => 'Без комиссии',
                    }),
                TextColumn::make('value')->label('Ставка')
                    ->formatStateUsing(fn (VenueCommissionAgent $record): string =>
                        $this->rateState($record->mode, $record->value, $record->currency, false)['value_text']
                        .($record->mode === 'percent' ? '%' : ($record->mode === 'fixed' ? ' руб.' : ''))),
                IconColumn::make('is_active')->label('Действует')->boolean(),
                IconColumn::make('selected')->label('В расчёте')->boolean()
                    ->state(fn (VenueCommissionAgent $record): bool =>
                        (int) $this->getOwnerRecord()->commissionPolicy?->selected_agent_id === (int) $record->id),
                TextColumn::make('version')->label('Версия')->numeric(),
            ])
            ->emptyStateHeading('Коммерческие агенты ещё не назначены')
            ->emptyStateDescription('Агент не получает комиссию автоматически при выдаче прав сотрудника.')
            ->headerActions([
                Action::make('portalCommission')->label('Комиссия MSKBA · глобально')
                    ->icon('heroicon-o-globe-alt')
                    ->modalHeading('Глобальная комиссия MSKBA')
                    ->modalDescription('Изменение влияет на новые расчёты всех площадок без переопределения. Существующие бронирования не пересчитываются.')
                    ->requiresConfirmation()
                    ->modalWidth(Width::Large)
                    ->schema($this->rateFields())
                    ->fillForm(fn (): array => $this->rateState(
                        PortalCommissionPolicy::query()->find(1)?->mode,
                        PortalCommissionPolicy::query()->find(1)?->value,
                        'RUB', false,
                    ))
                    ->action(function (array $data): void {
                        $this->manager()->setPortal($this->actor(), $data['mode'], $data['value_text']);
                        $this->toast('Глобальная комиссия сохранена');
                    }),
                Action::make('venueCommission')->label('Комиссия площадки')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->modalHeading('Комиссия площадки')
                    ->modalDescription('Пустое наследование — глобальная комиссия. Выбранный агент один; остальные не участвуют в расчёте.')
                    ->modalWidth(Width::Large)
                    ->schema([
                        Section::make('Комиссия MSKBA')->columns(2)
                            ->schema($this->rateFields(true)),
                        Select::make('selected_agent_id')->label('Агент, получающий комиссию')
                            ->placeholder('Не добавлять комиссию агента')
                            ->options(fn (): array => $this->getOwnerRecord()->commissionAgents()
                                ->where('is_active', true)->whereHas('user', fn ($q) =>
                                    $q->where('status', UserStatusEnum::CONFIRMED->value)->whereNull('deleted_at'))
                                ->with('user')->get()->mapWithKeys(fn (VenueCommissionAgent $agent): array =>
                                    [$agent->id => $agent->user?->username ?: 'Пользователь #'.$agent->user_id])->all())
                            ->searchable(),
                    ])
                    ->fillForm(function (): array {
                        $policy = $this->getOwnerRecord()->commissionPolicy;
                        return [
                            ...$this->rateState($policy?->mode_override, $policy?->value_override,
                                $this->getOwnerRecord()->bookingPolicyCurrency(), true),
                            'selected_agent_id' => $policy?->selected_agent_id,
                        ];
                    })
                    ->action(function (array $data): void {
                        $this->manager()->setVenue(
                            $this->getOwnerRecord(), $this->actor(), $data['mode'], $data['value_text'],
                            filled($data['selected_agent_id'] ?? null) ? (int) $data['selected_agent_id'] : null,
                        );
                        $this->getOwnerRecord()->unsetRelation('commissionPolicy');
                        $this->toast('Комиссия площадки сохранена');
                    }),
                Action::make('createCommissionAgent')->label('Добавить агента')
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading('Новый коммерческий агент')
                    ->modalDescription('Назначение комиссии не выдаёт сотруднику права на управление площадкой.')
                    ->modalWidth(Width::Large)
                    ->schema([
                        Select::make('user_id')->label('Пользователь')
                            ->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('status', UserStatusEnum::CONFIRMED->value)
                                ->whereNull('deleted_at')->whereNotNull('username')
                                ->where('username', 'like', '%'.$search.'%')
                                ->orderBy('username')->limit(25)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->username),
                        ...$this->rateFields(),
                        Toggle::make('is_active')->label('Активен')->default(true),
                    ])
                    ->action(function (array $data): void {
                        $this->manager()->saveAgent(
                            $this->getOwnerRecord(), $this->actor(),
                            User::query()->findOrFail((int) $data['user_id']),
                            $data['mode'], $data['value_text'], (bool) ($data['is_active'] ?? false),
                        );
                        $this->toast('Коммерческий агент сохранён');
                    }),
            ])
            ->recordActions([
                Action::make('editCommissionAgent')->label('Ставка агента')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (VenueCommissionAgent $record): string =>
                        'Агент: '.($record->user?->username ?: 'пользователь #'.$record->user_id))
                    ->modalWidth(Width::Large)
                    ->schema([
                        ...$this->rateFields(),
                        Toggle::make('is_active')->label('Активен')->default(true),
                    ])
                    ->fillForm(fn (VenueCommissionAgent $record): array => [
                        ...$this->rateState($record->mode, $record->value, $record->currency, false),
                        'is_active' => $record->is_active,
                    ])
                    ->action(function (VenueCommissionAgent $record, array $data): void {
                        $this->manager()->saveAgent(
                            $this->getOwnerRecord(), $this->actor(),
                            $record->user, $data['mode'], $data['value_text'], (bool) ($data['is_active'] ?? false),
                        );
                        $this->getOwnerRecord()->unsetRelation('commissionPolicy');
                        $this->toast('Ставка агента обновлена');
                    }),
            ]);
    }
}
