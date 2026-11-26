<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages\Concerns;

use App\Modules\Contact\Application\Exceptions\ContactDeletionException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationDeliveryException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\UseCases\AdminManageUserContactsHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

trait ManagesUserContacts
{
    /** @return list<Action> */
    protected function contactManagementActions(): array
    {
        $visible = fn (): bool => app(AdminManageUserContactsHandler::class)
            ->canManage(auth('web')->user(), $this->getRecord());

        return [
            Action::make('addUserContact')
                ->label('Добавить контакт')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->modalHeading('Добавить контакт')
                ->modalDescription('Контакт останется неподтверждённым. Подтверждённый email может использоваться для входа в аккаунт.')
                ->schema([
                    Select::make('type')->label('Тип')->options([
                        ContactTypeEnum::EMAIL->value => 'Email',
                        ContactTypeEnum::PHONE->value => 'Телефон',
                    ])->required()->default(ContactTypeEnum::EMAIL->value),
                    TextInput::make('value')->label('Контакт')->required()->maxLength(254),
                    TextInput::make('label')->label('Название')->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(AdminManageUserContactsHandler::class)->add(
                            $actor, $this->getRecord(), ContactTypeEnum::from($data['type']),
                            $data['value'], $data['label'] ?? null,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['value' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Контакт добавлен')->send();
                }),
            Action::make('sendUserContactCode')
                ->label('Отправить код')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->requiresConfirmation()
                ->modalHeading('Отправить код подтверждения')
                ->modalDescription('Код будет отправлен на email пользователя. Не используйте чужой контакт без согласования с владельцем аккаунта.')
                ->action(function (array $arguments): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(AdminManageUserContactsHandler::class)->sendCode(
                            $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0),
                        );
                    } catch (ContactVerificationCooldownException|ContactVerificationDeliveryException|LogicException $exception) {
                        Notification::make()->warning()->title($exception->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Код отправлен')->send();
                }),
            Action::make('confirmUserContactCode')
                ->label('Подтвердить кодом')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->modalHeading('Подтвердить email')
                ->modalDescription('Введите код, полученный владельцем email. Подтверждение позволяет использовать этот контакт для входа.')
                ->schema([
                    TextInput::make('code')->label('Код подтверждения')->required()
                        ->password()->maxLength(6)->minLength(6)->regex('/^\d{6}$/D'),
                ])
                ->action(function (array $arguments, array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(AdminManageUserContactsHandler::class)->confirmCode(
                            $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0), $data['code'],
                        );
                    } catch (ContactVerificationException|LogicException $exception) {
                        throw ValidationException::withMessages(['code' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Email подтверждён')->send();
                }),
            Action::make('editUserContactLabel')
                ->label('Изменить название')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->fillForm(function (array $arguments) use ($visible): array {
                    abort_unless($visible(), 403);
                    $contact = $this->getRecord()->identityContactsQuery()->findOrFail((int) ($arguments['contact_id'] ?? 0));

                    return ['label' => $contact->label];
                })
                ->schema([
                    TextInput::make('label')->label('Название')->maxLength(100),
                    Textarea::make('reason')->label('Основание')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $arguments, array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminManageUserContactsHandler::class)->updateLabel(
                        $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0), $data['label'] ?? null, $data['reason'],
                    );
                    Notification::make()->success()->title('Название изменено')->send();
                }),
            Action::make('replaceUserContact')
                ->label('Изменить контакт')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->modalHeading('Заменить неподтверждённый контакт')
                ->modalDescription('Старый контакт и его история сохранятся в удалённых. Новый контакт потребует отдельного подтверждения.')
                ->schema([
                    TextInput::make('value')->label('Новое значение')->required()->maxLength(254),
                    Textarea::make('reason')->label('Основание')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $arguments, array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(AdminManageUserContactsHandler::class)->replacePending(
                            $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0), $data['value'], $data['reason'],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['value' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Контакт заменён')->send();
                }),
            Action::make('deleteUserContact')
                ->label('Удалить контакт')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->color('danger')
                ->modalHeading('Удалить контакт')
                ->modalDescription('Контакт перестанет использоваться для входа. История сохранится. Подтверждённый основной контакт удалить нельзя.')
                ->modalSubmitActionLabel('Удалить')
                ->schema([
                    Textarea::make('reason')->label('Основание')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $arguments, array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(AdminManageUserContactsHandler::class)->delete(
                            $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0), $data['reason'],
                        );
                    } catch (ContactDeletionException $exception) {
                        throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Контакт удалён')->send();
                }),
            Action::make('setUserPrimaryContact')
                ->label('Сделать основным')
                ->extraAttributes(['style' => 'display:none'])
                ->visible($visible)
                ->requiresConfirmation()
                ->modalHeading('Назначить основным контактом')
                ->modalDescription('Основным можно назначить только уже подтверждённый контакт.')
                ->action(function (array $arguments): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminManageUserContactsHandler::class)->setPrimary(
                        $actor, $this->getRecord(), (int) ($arguments['contact_id'] ?? 0),
                    );
                    Notification::make()->success()->title('Основной контакт обновлён')->send();
                }),
        ];
    }
}
