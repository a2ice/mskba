<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PricingRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'pricingRules';
    protected static ?string $title = 'Тарифные условия и скидки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function canManagePricingRules(): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized()
            && $user !== null
            && app(VenueCommercialAccess::class)->allows(
                $user, $this->getOwnerRecord(), VenuePermissionEnum::MANAGE_BOOKING_POLICY,
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('status', ['draft', 'published'])->orderBy('priority')->orderByDesc('version'))
            ->columns([
                TextColumn::make('name')->label('Условие')->searchable(),
                TextColumn::make('court.name')->label('Зал')->placeholder('Все залы'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'draft' ? 'Черновик' : 'Опубликовано'),
                IconColumn::make('is_enabled')->label('Активно')->boolean(),
                TextColumn::make('operation')->label('Формула')->formatStateUsing(fn (string $state): string => match ($state) {
                    'percent_discount' => 'Скидка, %',
                    'fixed_discount' => 'Скидка, сумма',
                    'percent_surcharge' => 'Наценка, %',
                    'fixed_surcharge' => 'Наценка, сумма',
                    'fixed_total' => 'Итоговая цена',
                    'minimum_total' => 'Минимальная цена',
                    default => $state,
                }),
                TextColumn::make('priority')->label('Приоритет')->numeric(),
                TextColumn::make('version')->label('Версия')->numeric(),
            ])
            ->headerActions([
                Action::make('managePricingRules')->label('Настроить тарифы')
                    ->icon('heroicon-o-calculator')
                    ->visible(fn (): bool => $this->canManagePricingRules())
                    ->url(fn (): string => route('account.venues.pricing-rules.index', $this->getOwnerRecord()->id))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('editPricingRule')->label('Редактировать')
                    ->visible(fn (): bool => $this->canManagePricingRules())
                    ->url(fn (VenuePricingRule $record): string => route('account.venues.pricing-rules.index', [
                        $record->venue_id, 'edit' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
                Action::make('publishPricingRule')->label('Опубликовать')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (VenuePricingRule $record): bool => $this->canManagePricingRules() && $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (VenuePricingRule $record): void {
                        abort_unless($this->canManagePricingRules(), 403);
                        app(VenuePricingRuleManager::class)->publish(
                            $this->getOwnerRecord(), auth('web')->user(), $record,
                        );
                        Notification::make()->success()->title('Тариф опубликован')->send();
                    }),
            ]);
    }
}
