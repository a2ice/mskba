<?php

namespace App\Filament\Backoffice\Resources\Venues\Support;

use App\Modules\Event\Application\Services\EventVenueBookingProposals;
use App\Modules\Event\Application\Services\EventVenueInterestChat;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum as Status;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\SendVenueBookingMessageHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Support\Features\FeatureDisabledException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared table UI for the venue tab and global backoffice inbox.
 * All mutations use the existing domain handlers: no direct status updates.
 */
final class VenueRentalSupportTables
{
    private static function access(): VenueBookingBackofficeAccess
    {
        return app(VenueBookingBackofficeAccess::class);
    }

    private static function managing(): bool
    {
        return self::access()->canManage(auth('web')->user());
    }

    private static function guard(VenueBooking|EventVenueInterest $record): void
    {
        self::access()->actor();
        abort_unless($record->venue_id !== null, 404);
    }

    private static function done(callable $command, string $success): void
    {
        try {
            $command();
            Notification::make()->success()->title($success)->send();
        } catch (VenueBookingTransitionException|VenueBookingPolicyException|FeatureDisabledException|InvalidArgumentException $error) {
            Notification::make()->danger()->title('Действие не выполнено')
                ->body($error instanceof FeatureDisabledException ? 'Аренда временно отключена.' : $error->getMessage())->persistent()->send();
        }
    }

    public static function bookings(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_id')->label('Заявка')->searchable()->copyable()->limit(12),
                TextColumn::make('venue.name')->label('Площадка')->searchable(),
                TextColumn::make('court.name')->label('Зал'),
                TextColumn::make('requester.username')->label('Заявитель')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof Status
                        ? $state->label() : (Status::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('starts_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('ends_at')->label('Окончание')->dateTime('d.m.Y H:i'),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('venue_id')->label('Площадка')
                    ->relationship('venue', 'name')->searchable(),
                SelectFilter::make('status')->label('Статус')
                    ->options(collect(Status::cases())->mapWithKeys(
                        fn (Status $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('details')->label('История и переписка')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading(fn (VenueBooking $record): string => 'Заявка '.$record->public_id)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalContent(function (VenueBooking $record) {
                        self::access()->assertView();
                        self::access()->logConversationView($record);
                        $record->load([
                            'venue', 'requester', 'court', 'venueInterest.event',
                            'transitions.actor.user', 'conversation.messages.authorActor.user',
                        ]);

                        return view('filament.backoffice.venue-rental-booking-history', ['booking' => $record]);
                    })
                    ->modalSubmitAction(false)->modalCancelActionLabel('Закрыть'),
                Action::make('reply')->label('Написать')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (VenueBooking $record): bool => self::managing() && $record->flow === 'rental')
                    ->schema([Textarea::make('body')->label('Сообщение участникам заявки')->required()->maxLength(4000)])
                    ->modalSubmitActionLabel('Отправить')
                    ->action(function (VenueBooking $record, array $data): void {
                        self::guard($record);
                        self::done(fn () => app(SendVenueBookingMessageHandler::class)->handle(
                            $record->id, self::access()->actor(), (string) Str::uuid(), (string) $data['body'],
                        ), 'Сообщение отправлено');
                    }),
                Action::make('accept')->label('Принять')
                    ->icon('heroicon-o-check')
                    ->visible(fn (VenueBooking $record): bool => self::managing()
                        && $record->flow === 'rental' && $record->status === Status::REQUESTED)
                    ->requiresConfirmation()
                    ->action(function (VenueBooking $record): void {
                        self::guard($record);
                        self::done(fn () => app(AcceptVenueBookingHandler::class)->handle(
                            $record->id, self::access()->actor(), $record->optimistic_version, (string) Str::uuid(),
                        ), 'Заявка принята');
                    }),
                Action::make('confirm')->label('Подтвердить')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (VenueBooking $record): bool => self::managing()
                        && $record->flow === 'rental' && $record->status === Status::HELD)
                    ->requiresConfirmation()
                    ->action(function (VenueBooking $record): void {
                        self::guard($record);
                        self::done(fn () => app(ConfirmVenueBookingHandler::class)->handle(
                            $record->id, self::access()->actor(), $record->optimistic_version, (string) Str::uuid(),
                        ), 'Бронирование подтверждено');
                    }),
                Action::make('reject')->label('Отклонить')->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (VenueBooking $record): bool => self::managing()
                        && $record->flow === 'rental' && $record->status === Status::REQUESTED)
                    ->schema([Textarea::make('reason')->label('Причина отклонения')->required()->maxLength(2000)])
                    ->requiresConfirmation()
                    ->action(function (VenueBooking $record, array $data): void {
                        self::guard($record);
                        self::done(fn () => app(RejectVenueBookingHandler::class)->handle(
                            $record->id, self::access()->actor(), $data['reason'],
                            $record->optimistic_version, (string) Str::uuid(),
                        ), 'Заявка отклонена');
                    }),
                Action::make('cancel')->label('Отменить')->color('danger')
                    ->icon('heroicon-o-no-symbol')
                    ->visible(fn (VenueBooking $record): bool => self::managing()
                        && $record->flow === 'rental'
                        && in_array($record->status, [Status::HELD, Status::CONFIRMED], true))
                    ->schema([Textarea::make('reason')->label('Причина отмены')->required()->maxLength(2000)])
                    ->requiresConfirmation()
                    ->action(function (VenueBooking $record, array $data): void {
                        self::guard($record);
                        self::done(fn () => app(CancelVenueBookingHandler::class)->handle(
                            $record->id, self::access()->actor(), $data['reason'],
                            $record->optimistic_version, (string) Str::uuid(),
                        ), 'Бронирование отменено');
                    }),
            ])
            ->emptyStateHeading('Заявок на аренду пока нет');
    }

    public static function interests(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Предзаявка')->sortable(),
                TextColumn::make('venue.name')->label('Площадка')->searchable(),
                TextColumn::make('event.title')->label('Мероприятие')->searchable(),
                TextColumn::make('court.name')->label('Зал'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'open' => 'Открыта', 'revised' => 'Пересмотрена',
                        'booking_requested' => 'Заявка оформлена', 'withdrawn' => 'Отозвана',
                        default => (string) $state,
                    }),
                TextColumn::make('starts_at')->label('Дата аренды')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('venue_id')->label('Площадка')
                    ->relationship('venue', 'name')->searchable(),
                SelectFilter::make('status')->label('Статус')
                    ->options(['open' => 'Открыта', 'revised' => 'Пересмотрена',
                        'booking_requested' => 'Заявка оформлена', 'withdrawn' => 'Отозвана']),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('details')->label('История и переписка')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading(fn (EventVenueInterest $record): string => 'Предзаявка #'.$record->id)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalContent(function (EventVenueInterest $record) {
                        self::access()->assertView();
                        self::access()->logConversationView($record);
                        $record->load([
                            'venue', 'court', 'event.organizerActor.user', 'booking',
                            'proposals.initiator.user', 'proposals.booking',
                            'conversation.messages.authorActor.user',
                        ]);

                        return view('filament.backoffice.venue-rental-interest-history', ['interest' => $record]);
                    })
                    ->modalSubmitAction(false)->modalCancelActionLabel('Закрыть'),
                Action::make('reply')->label('Написать')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (EventVenueInterest $record): bool => self::managing())
                    ->schema([Textarea::make('body')->label('Сообщение в переписку')->required()->maxLength(4000)])
                    ->modalSubmitActionLabel('Отправить')
                    ->action(function (EventVenueInterest $record, array $data): void {
                        self::guard($record);
                        self::done(fn () => app(EventVenueInterestChat::class)->send(
                            $record, self::access()->actor(), (string) Str::uuid(), (string) $data['body'],
                        ), 'Сообщение отправлено');
                    }),
                Action::make('propose')->label('Предложить бронь')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (EventVenueInterest $record): bool => self::managing()
                        && $record->status === 'open')
                    ->requiresConfirmation()
                    ->modalDescription('Создаст предложение аренды организатору через существующий процесс предзаявок.')
                    ->action(function (EventVenueInterest $record): void {
                        self::guard($record);
                        self::done(fn () => app(EventVenueBookingProposals::class)->offer(
                            $record, self::access()->actor(),
                        ), 'Предложение отправлено организатору');
                    }),
            ])
            ->emptyStateHeading('Предзаявок пока нет');
    }
}
