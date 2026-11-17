<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Filament\Backoffice\Resources\Venues\Pages\ListVenueDuplicates;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
use App\Modules\Venue\Application\UseCases\MergeVenueDuplicateHandler;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class VenueDuplicateResource extends Resource
{
    protected static ?string $model = VenueDuplicate::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string { return 'Дубли площадок'; }
    public static function getModelLabel(): string { return 'кандидат дубля'; }
    public static function getPluralModelLabel(): string { return 'кандидаты дублей'; }

    public static function canViewAny(): bool
    {
        return app(BackofficeVenueModerationHandler::class)->canReview(auth('web')->user());
    }
    public static function canView(Model $record): bool
    {
        return $record instanceof VenueDuplicate && self::canViewAny();
    }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['venue', 'duplicateVenue', 'resolvedByUser']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('venue.name')->label('Площадка A')->searchable(),
            TextColumn::make('duplicateVenue.name')->label('Площадка Б')->searchable(),
            TextColumn::make('matched_by')->label('Причина')->formatStateUsing(
                fn ($state): string => $state instanceof \App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum
                    ? $state->label() : '—'),
            TextColumn::make('score')->label('Совпадение')->sortable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof VenueDuplicateStatusEnum
                    ? $state->label() : '—'),
            TextColumn::make('resolvedByUser.username')->label('Рассмотрел'),
        ])->filters([
            SelectFilter::make('status')->label('Статус')->options([
                'pending' => 'Ожидает решения', 'merged' => 'Объединён', 'rejected' => 'Отклонён',
            ]),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('merge')->label('Объединить')->icon('heroicon-o-arrows-pointing-in')
                ->color('warning')
                ->visible(fn (VenueDuplicate $record): bool => self::canView($record)
                    && $record->status === VenueDuplicateStatusEnum::PENDING)
                ->schema([
                    Select::make('canonical_venue_id')->label('Какая площадка останется основной?')
                        ->required()->options(fn (VenueDuplicate $record): array => [
                            $record->venue_id => $record->venue?->name.' (#'.$record->venue_id.')',
                            $record->duplicate_venue_id => $record->duplicateVenue?->name.' (#'.$record->duplicate_venue_id.')',
                        ]),
                ])->requiresConfirmation()
                ->action(function (VenueDuplicate $record, array $data): void {
                    abort_unless(self::canView($record), 403);
                    $canonicalId = (int) $data['canonical_venue_id'];
                    abort_unless(in_array($canonicalId, [(int) $record->venue_id,
                        (int) $record->duplicate_venue_id], true), 422);
                    $otherId = $canonicalId === (int) $record->venue_id
                        ? (int) $record->duplicate_venue_id : (int) $record->venue_id;
                    try {
                        app(MergeVenueDuplicateHandler::class)->handle(
                            $record, $canonicalId, $otherId, auth('web')->user());
                        Notification::make()->success()->title('Кандидат дубля объединён')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVenueDuplicates::route('/')];
    }
}
