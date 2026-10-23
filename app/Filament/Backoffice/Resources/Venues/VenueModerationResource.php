<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Filament\Backoffice\Resources\Venues\Pages\ListVenueModeration;
use App\Filament\Backoffice\Resources\Venues\Pages\ViewVenueModeration;
use App\Modules\Admin\Application\Services\VenueRevisionDiffBuilder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class VenueModerationResource extends Resource
{
    protected static ?string $model = ModerationRequest::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string { return 'Модерация площадок'; }
    public static function getModelLabel(): string { return 'заявка на модерацию площадки'; }
    public static function getPluralModelLabel(): string { return 'заявки на модерацию площадок'; }

    public static function canViewAny(): bool
    {
        return app(BackofficeVenueModerationHandler::class)->canReview(auth('web')->user());
    }
    public static function canView(Model $record): bool
    {
        return $record instanceof ModerationRequest
            && $record->type === ModerationTypeEnum::VENUE && self::canViewAny();
    }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', ModerationTypeEnum::VENUE->value)
            ->with(['venue', 'submittedByActor.user', 'reviewedByUser', 'messages.sender.user',
                'venueRevision.media']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')->columns(2)->schema([
                TextEntry::make('id')->label('Номер'),
                TextEntry::make('status')->label('Решение')
                    ->formatStateUsing(fn ($state): string => $state instanceof ModerationRequestStatusEnum
                        ? $state->label() : (ModerationRequestStatusEnum::tryFrom((string) $state)?->label() ?? '—')),
                TextEntry::make('venue.name')->label('Площадка')->url(
                    fn (ModerationRequest $record): ?string => $record->venue !== null
                        ? VenueResource::getUrl('edit', ['record' => $record->venue]) : null
                ),
                TextEntry::make('submittedByActor.user.username')->label('Подал')->placeholder('Системный актор'),
                TextEntry::make('reviewedByUser.username')->label('Рассмотрел')->placeholder('Не рассмотрена'),
                TextEntry::make('submitted_at')->label('Подана')->dateTime('d.m.Y H:i'),
                TextEntry::make('reviewed_at')->label('Рассмотрена')->dateTime('d.m.Y H:i')->placeholder('—'),
            ]),
            Section::make('Предлагаемые изменения')->schema([
                TextEntry::make('revision_changes')->label('Разница с опубликованной версией')
                    ->state(function (ModerationRequest $record): string {
                        if ($record->venue === null || $record->venueRevision === null) {
                            return 'Заявка на первоначальное подтверждение площадки.';
                        }
                        $diff = app(VenueRevisionDiffBuilder::class)->build($record->venue, $record->venueRevision);
                        $lines = array_map(static fn (array $entry): string =>
                            $entry['label'].': '.$entry['before'].' → '.$entry['after'], $diff['fields']);

                        if ($diff['gallery_changed']) {
                            $lines[] = 'Фотографии: '.$diff['gallery_summary'];
                        }

                        return $lines === [] ? 'Изменений не обнаружено.' : implode("\n", $lines);
                    })->columnSpanFull(),
            ]),
            Section::make('История переписки')->schema([
                TextEntry::make('discussion')->label('Сообщения')
                    ->state(fn (ModerationRequest $record): string => $record->messages
                        ->sortBy('id')
                        ->map(static fn ($message): string =>
                            ($message->sender?->user?->username ?? 'Система')
                            .' · '.$message->created_at?->format('d.m.Y H:i')
                            ."\n".$message->message)->implode("\n\n") ?: 'Нет сообщений')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<int, Action> */
    public static function decisionActions(): array
    {
        return [
            Action::make('approve')->label('Одобрить')->icon('heroicon-o-check-circle')
                ->color('success')->requiresConfirmation()
                ->visible(fn (ModerationRequest $record): bool =>
                    self::canView($record) && $record->status === ModerationRequestStatusEnum::PENDING)
                ->action(function (ModerationRequest $record): void {
                    abort_unless(self::canView($record), 403);
                    try {
                        app(BackofficeVenueModerationHandler::class)->approve($record, auth('web')->user());
                        Notification::make()->success()->title('Заявка одобрена')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('reject')->label('Отклонить')->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (ModerationRequest $record): bool =>
                    self::canView($record) && $record->status === ModerationRequestStatusEnum::PENDING)
                ->schema([Textarea::make('message')->label('Причина для заявителя')
                    ->required()->maxLength(5000)])
                ->requiresConfirmation()
                ->action(function (ModerationRequest $record, array $data): void {
                    abort_unless(self::canView($record), 403);
                    try {
                        app(BackofficeVenueModerationHandler::class)->reject(
                            $record, auth('web')->user(), (string) $data['message']);
                        Notification::make()->success()->title('Заявка отклонена')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('venue.name')->label('Площадка')->searchable(),
            TextColumn::make('submittedByActor.user.username')->label('Отправитель'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof ModerationRequestStatusEnum
                    ? $state->label() : (ModerationRequestStatusEnum::tryFrom((string) $state)?->label() ?? '—')),
            TextColumn::make('submitted_at')->label('Создана')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('reviewedByUser.username')->label('Модератор'),
        ])->filters([
            SelectFilter::make('status')->label('Результат')->options([
                'pending' => 'На рассмотрении', 'approved' => 'Одобрено', 'rejected' => 'Отклонено',
            ]),
        ])->defaultSort('id', 'desc')->recordActions([
            ViewAction::make(), ...self::decisionActions(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenueModeration::route('/'),
            'view' => ViewVenueModeration::route('/{record}'),
        ];
    }
}
