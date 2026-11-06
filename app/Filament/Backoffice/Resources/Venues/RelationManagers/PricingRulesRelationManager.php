<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
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
                    ->visible(fn (): bool => VenueResource::authorized())
                    ->url(fn (): string => route('account.venues.pricing-rules.index', $this->getOwnerRecord()->id))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('editPricingRule')->label('Редактировать')
                    ->visible(fn (): bool => VenueResource::authorized())
                    ->url(fn (VenuePricingRule $record): string => route('account.venues.pricing-rules.index', [
                        $record->venue_id, 'edit' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
                Action::make('publishPricingRule')->label('Опубликовать')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (VenuePricingRule $record): bool => VenueResource::authorized() && $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (VenuePricingRule $record): void {
                        abort_unless(VenueResource::authorized(), 403);
                        app(VenuePricingRuleManager::class)->publish(
                            $this->getOwnerRecord(), auth('web')->user(), $record,
                        );
                        Notification::make()->success()->title('Тариф опубликован')->send();
                    }),
            ]);
    }
}
