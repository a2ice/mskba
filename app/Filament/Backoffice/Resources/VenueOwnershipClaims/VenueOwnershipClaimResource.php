<?php

namespace App\Filament\Backoffice\Resources\VenueOwnershipClaims;

use App\Filament\Backoffice\Resources\VenueOwnershipClaims\Pages\ListVenueOwnershipClaims;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum as ClaimStatus;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Presentation\Http\Controllers\VenueOwnershipClaimConversationController;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VenueOwnershipClaimResource extends Resource
{
    protected static ?string $model = VenueOwnershipClaim::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Аренда и документы';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Подтверждение площадок'; }
    public static function getModelLabel(): string { return 'заявка на полномочия'; }
    public static function getPluralModelLabel(): string { return 'заявки на полномочия'; }

    public static function canViewAny(): bool
    {
        $user = auth('web')->user()?->canonical();
        return $user !== null && $user->isConfirmed()
            && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof VenueOwnershipClaim && self::canViewAny() && $record->submitted_at !== null;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('submitted_at')
            ->with(['venue', 'applicant', 'conversation', 'reviewer']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('venue.name')->label('Площадка')->searchable()->wrap(),
            TextColumn::make('applicant.username')->label('Заявитель')->searchable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state, VenueOwnershipClaim $record): string => $record->status->label()),
            TextColumn::make('submitted_at')->label('Подано')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('reviewer.username')->label('Проверил')->placeholder('—'),
        ])->defaultSort('submitted_at', 'desc')->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(collect(ClaimStatus::cases())->mapWithKeys(fn (ClaimStatus $s): array => [$s->value => $s->label()])->all()),
        ])->recordActions([
            Action::make('inspect')->label('Карточка и диалог')->icon('heroicon-o-chat-bubble-left-right')
                ->modalWidth('3xl')->modalSubmitAction(false)
                ->modalHeading(fn (VenueOwnershipClaim $record): string => 'Заявка #'.$record->id)
                ->modalContent(fn (VenueOwnershipClaim $record) => view(
                    'filament.backoffice.venue-ownership-case-preview', [
                        'claim' => $record->load(['venue', 'applicant', 'documents', 'conversation.messages.authorActor.user']),
                    ],
                )),
            Action::make('reply')->label('Написать')
                ->visible(fn (VenueOwnershipClaim $record): bool => $record->status === ClaimStatus::PENDING)
                ->schema([Textarea::make('body')->label('Сообщение заявителю')->required()->maxLength(4000)])
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    abort_unless(self::canView($record), 403);
                    $request = request();
                    $request->merge(['client_id' => (string) Str::uuid(), 'body' => $data['body']]);
                    // Use the same authenticated write path and broadcast as the portal chat.
                    app(VenueOwnershipClaimConversationController::class)->store(
                        $request, $record, app(CurrentActorResolver::class),
                    );
                    Notification::make()->success()->title('Сообщение доставлено в общий диалог')->send();
                }),
            Action::make('requestFiles')->label('Запросить документы')
                ->visible(fn (VenueOwnershipClaim $record): bool => $record->status === ClaimStatus::PENDING)
                ->schema([Textarea::make('body')->label('Какие документы нужны?')->required()->maxLength(4000)])
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    abort_unless(self::canView($record), 403);
                    $request = request();
                    $request->merge([
                        'client_id' => (string) Str::uuid(),
                        'body' => $data['body'],
                        'short_code' => 'files_requested',
                    ]);
                    app(VenueOwnershipClaimConversationController::class)->store(
                        $request, $record, app(CurrentActorResolver::class),
                    );
                    Notification::make()->success()->title('Запрос документов отправлен')->send();
                }),
            Action::make('approve')->label('Подтвердить')->color('success')
                ->visible(fn (VenueOwnershipClaim $record): bool => $record->status === ClaimStatus::PENDING)
                ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(2000)])
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    app(ReviewVenueOwnershipClaimHandler::class)->approve($record, auth('web')->user(), $data['reason'] ?? null);
                    Notification::make()->success()->title('Полномочия подтверждены, контракт создан')->send();
                }),
            Action::make('reject')->label('Отклонить')->color('warning')
                ->visible(fn (VenueOwnershipClaim $record): bool => $record->status === ClaimStatus::PENDING)
                ->schema([Textarea::make('reason')->label('Причина')->required()->minLength(5)->maxLength(2000)])
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    app(ReviewVenueOwnershipClaimHandler::class)->reject($record, auth('web')->user(), $data['reason']);
                    Notification::make()->success()->title('Отклонено; повторная заявка разрешена')->send();
                }),
            Action::make('block')->label('Заблокировать')->color('danger')
                ->visible(fn (VenueOwnershipClaim $record): bool => in_array($record->status, [ClaimStatus::PENDING, ClaimStatus::REJECTED], true))
                ->schema([Textarea::make('reason')->label('Причина блокировки')->required()->minLength(5)->maxLength(2000)])
                ->requiresConfirmation()
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    DB::transaction(function () use ($record, $data): void {
                        $reviewer = auth('web')->user();
                        app(ReviewVenueOwnershipClaimHandler::class)->block($record, $reviewer, $data['reason']);
                        $record->loadMissing(['venue', 'applicant']);
                        app(VenueUserRestrictionService::class)->impose(
                            $record->venue, $record->applicant,
                            VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM, $data['reason'], $reviewer,
                        );
                    });
                    Notification::make()->success()->title('Заявка и повторная подача заблокированы')->send();
                }),
            Action::make('unblock')->label('Снять блокировку')->color('gray')
                ->visible(fn (VenueOwnershipClaim $record): bool => $record->status === ClaimStatus::BLOCKED)
                ->schema([Textarea::make('reason')->label('Причина снятия ограничения')->maxLength(2000)])
                ->action(function (VenueOwnershipClaim $record, array $data): void {
                    $record->loadMissing(['venue', 'applicant']);
                    $service = app(VenueUserRestrictionService::class);
                    $restriction = $service->active($record->venue, $record->applicant, VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM);
                    if ($restriction) {
                        $service->revoke($restriction, auth('web')->user(), $data['reason'] ?? null);
                    }
                    Notification::make()->success()->title('Блокировка снята; можно подать новую заявку')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVenueOwnershipClaims::route('/')];
    }
}
