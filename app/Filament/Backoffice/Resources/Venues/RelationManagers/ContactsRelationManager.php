<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\AccountInvitations\AccountInvitationResource;
use App\Modules\Invitation\Application\Services\AccountInvitationAccess;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationDeliveryException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Venue\Application\Services\VenueContactConnectionStatus;
use App\Modules\Venue\Application\Services\VenueContactHistory;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

final class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Контакты';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Venue
            && app(AdminManageVenueContactsHandler::class)->canManage(auth('web')->user(), $ownerRecord);
    }

    private function actor(): User
    {
        $actor = auth('web')->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function venue(): Venue
    {
        return $this->getOwnerRecord();
    }

    private function manager(): AdminManageVenueContactsHandler
    {
        return app(AdminManageVenueContactsHandler::class);
    }

    private function fields(bool $edit = false): array
    {
        return [
            Select::make('type')->label('Тип')->options(['email' => 'Email', 'phone' => 'Телефон'])
                ->default('email')->required(),
            TextInput::make('value')->label('Контакт')->required()->maxLength(254),
            TextInput::make('label')->label('Название / назначение')->maxLength(100)
                ->placeholder('Например, отдел бронирования'),
            Textarea::make('comment')->label('Внутренний комментарий')->rows(3)->maxLength(500),
            Toggle::make('is_public')->label('Публичный контакт')->default(false)
                ->helperText($edit
                    ? 'Можно публиковать без подтверждения, если вы удостоверились, что контакт предназначен для посетителей. При изменении типа или значения сохраните контакт и включите публичность заново.'
                    : 'Включайте только для контакта, предназначенного для посетителей. Подтверждение не обязательно.'),
        ];
    }

    private function editable(Contact $record): bool
    {
        return ! $record->trashed() && in_array($record->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true);
    }

    private function run(Action $action, callable $operation, string $success): void
    {
        try {
            $operation();
        } catch (ValidationException | ContactVerificationException | ContactVerificationDeliveryException | ContactVerificationCooldownException | \LogicException $exception) {
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : $exception->getMessage();
            Notification::make()->danger()->title('Не удалось выполнить действие')->body($message)->send();
            $action->halt();

            return;
        }
        Notification::make()->success()->title($success)->send();
    }

    private function canInvite(Contact $contact): bool
    {
        $actor = auth('web')->user();
        $venue = $this->venue();
        if (! $this->manager()->canManage($actor, $venue)
            || $contact->trashed() || $contact->type !== ContactTypeEnum::EMAIL || ! $contact->hasBeenVerified()) {
            return false;
        }
        foreach (['owner', 'staff', 'manager', 'booking_operator'] as $role) {
            if (app(AccountInvitationAccess::class)->canIssue($actor, $venue, $role)) {
                return true;
            }
        }

        return false;
    }

    private function connectionStatus(Contact $record): string
    {
        $status = app(VenueContactConnectionStatus::class)->resolve(auth('web')->user(), $this->venue(), $record);
        $parts = [];
        if ($status['user'] !== null) {
            $parts[] = 'Аккаунт: '.$status['user']['username']
                .($status['user']['confirmed'] ? '' : ' (не подтверждён)');
        }
        foreach ($status['contracts'] as $contract) {
            $parts[] = 'Активный контракт #'.$contract['id'].' — '.$contract['role'];
        }
        if ($status['invitation'] !== null) {
            $invitation = $status['invitation'];
            $parts[] = 'Приглашение #'.$invitation['id'].' — '.$invitation['status'].' / '.$invitation['progress'];
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(SoftDeletingScope::class))
            ->filters([TrashedFilter::make()->label('Архив контактов')])
            ->columns([
                TextColumn::make('type')->label('Тип')->formatStateUsing(fn (ContactTypeEnum $state): string => $state->label()),
                TextColumn::make('value')->label('Контакт')->searchable(),
                TextColumn::make('label')->label('Назначение')->placeholder('—')->searchable(),
                TextColumn::make('comment')->label('Комментарий')->limit(60)->tooltip(fn (Contact $record): ?string => $record->comment),
                IconColumn::make('is_primary')->label('Основной')->boolean(),
                IconColumn::make('is_public')->label('Публичный')->boolean(),
                TextColumn::make('verified_at')->label('Подтверждён')->dateTime('d.m.Y H:i')->placeholder('Нет'),
                TextColumn::make('deleted_at')->label('Удалён')->dateTime('d.m.Y H:i')->placeholder('—')->toggleable(),
                TextColumn::make('connection_status')->label('Подключение')->wrap()
                    ->visible(fn (): bool => app(VenueContactConnectionStatus::class)->canView(auth('web')->user(), $this->venue()))
                    ->state(fn (Contact $record): string => $this->connectionStatus($record)),
            ])
            ->defaultSort('id')
            ->headerActions([
                Action::make('addContact')->label('Добавить контакт')->icon('heroicon-o-plus')
                    ->schema($this->fields())
                    ->action(fn (Action $action, array $data) => $this->run($action,
                        fn () => $this->manager()->add($this->actor(), $this->venue(), $data), 'Контакт добавлен')),
            ])
            ->recordActions([
                Action::make('contactHistory')->label('История')->icon('heroicon-o-clock')
                    ->modalHeading('История контакта')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Contact $record) => view('filament.backoffice.venue-contact-history',
                        app(VenueContactHistory::class)->resolve(auth('web')->user(), $this->venue(), $record->id))),
                Action::make('editContact')->label('Редактировать')->icon('heroicon-o-pencil-square')
                    ->visible(fn (Contact $record): bool => $this->editable($record))
                    ->fillForm(fn (Contact $record): array => [
                        'type' => $record->type->value, 'value' => $record->value, 'label' => $record->label,
                        'comment' => $record->comment, 'is_public' => $record->is_public,
                    ])
                    ->schema($this->fields(true))
                    ->action(fn (Action $action, Contact $record, array $data) => $this->run($action,
                        fn () => $this->manager()->edit($this->actor(), $this->venue(), $record->id, $data), 'Контакт сохранён')),
                Action::make('sendCode')->label('Отправить код')->icon('heroicon-o-envelope')
                    ->visible(fn (Contact $record): bool => ! $record->trashed() && $record->type === ContactTypeEnum::EMAIL && ! $record->hasBeenVerified())
                    ->requiresConfirmation()
                    ->modalDescription('Код будет отправлен на email контакта площадки. Это не подтверждает личность или договорные полномочия.')
                    ->action(fn (Action $action, Contact $record) => $this->run($action,
                        fn () => $this->manager()->sendCode($this->actor(), $this->venue(), $record->id), 'Код отправлен')),
                Action::make('confirmCode')->label('Подтвердить')->icon('heroicon-o-check-circle')
                    ->visible(fn (Contact $record): bool => ! $record->trashed() && $record->type === ContactTypeEnum::EMAIL && ! $record->hasBeenVerified())
                    ->schema([TextInput::make('code')->label('Код из письма')->required()->length(6)->regex('/^\d{6}$/D')])
                    ->action(fn (Action $action, Contact $record, array $data) => $this->run($action,
                        fn () => $this->manager()->confirmCode($this->actor(), $this->venue(), $record->id, $data['code']), 'Контакт подтверждён')),
                Action::make('inviteContact')->label('Пригласить')->icon('heroicon-o-user-plus')
                    ->visible(fn (Contact $record): bool => $this->canInvite($record))
                    ->modalHeading(fn (): string => 'Приглашение — '.$this->venue()->name)
                    ->modalDescription('Приглашение будет отправлено на подтверждённый контакт площадки. Полномочия предоставляются только после выполнения условий подключения.')
                    ->fillForm(fn (Contact $record): array => ['email' => $record->value, 'days' => 7])
                    ->schema(fn (): array => AccountInvitationResource::issueFields(fn (): Venue => $this->venue(), true))
                    ->action(function (Action $action, Contact $record, array $data): void {
                        abort_unless($this->canInvite($record), 403);
                        try {
                            [$invitation] = app(AccountInvitationManager::class)->create(
                                $this->actor(), (string) $data['email'], 'venue', $this->venue()->id,
                                (string) $data['role'], (string) ($data['title'] ?? ''),
                                $data['description'] ?? null, (int) $data['days'], $record->id,
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Не удалось отправить приглашение')
                                ->body(collect($exception->errors())->flatten()->first())->send();
                            $action->halt();

                            return;
                        } catch (\Illuminate\Auth\Access\AuthorizationException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                            throw $exception;
                        } catch (\Throwable $exception) {
                            // Transport failures can leave a created record. Never expose the bearer URL.
                            Notification::make()->danger()->title('Не удалось отправить приглашение')
                                ->body('Проверьте почтовый транспорт и раздел приглашений. Если запись создана, обновите ссылку для повторной отправки.')->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->success()->title('Приглашение #'.$invitation->id.' отправлено')->send();
                    }),
                Action::make('setPrimary')->label('Сделать основным')->icon('heroicon-o-star')
                    ->visible(fn (Contact $record): bool => ! $record->trashed() && ! $record->is_primary)
                    ->requiresConfirmation()
                    ->action(fn (Action $action, Contact $record) => $this->run($action,
                        fn () => $this->manager()->setPrimary($this->actor(), $this->venue(), $record->id), 'Основной контакт изменён')),
                Action::make('deleteContact')->label('Удалить')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (Contact $record): bool => $this->editable($record))
                    ->requiresConfirmation()
                    ->action(fn (Action $action, Contact $record) => $this->run($action,
                        fn () => $this->manager()->delete($this->actor(), $this->venue(), $record->id), 'Контакт удалён')),
            ]);
    }
}
