<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\UseCases\AdminCreateUserHandler;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Filament\Forms\Components\Checkbox;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListBackofficeUsers extends ListRecords
{
    protected static string $resource = BackofficeUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createUser')
                ->label('Создать пользователя')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => app(AdminCreateUserHandler::class)->canCreate(auth('web')->user()))
                ->modalHeading('Создать пользователя')
                ->modalDescription('Будет создан неподтверждённый аккаунт с логином и паролем, без контактов. Остальные данные заполняются в редакторе.')
                ->modalSubmitActionLabel('Создать')
                ->schema([
                    TextInput::make('username')
                        ->label('Логин')
                        ->required()
                        ->minLength(UsernameVO::MIN_LENGTH)
                        ->maxLength(UsernameVO::MAX_LENGTH),
                    TextInput::make('password')->label('Пароль')->password()->revealable()->required()
                        ->minLength(PasswordVO::MIN_LENGTH)->maxLength(PasswordVO::MAX_LENGTH),
                    TextInput::make('password_confirmation')->label('Повторите пароль')->password()->revealable()->required()
                        ->same('password')->maxLength(PasswordVO::MAX_LENGTH),
                    Checkbox::make('consent_attested')
                        ->label('Подтверждаю, что получил согласие пользователя на обработку персональных данных')
                        ->helperText('Согласие: /personal-data-consent. Фиксируется подтверждение администратора, а не действие от имени пользователя.')
                        ->accepted(),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $user = app(AdminCreateUserHandler::class)->handle(
                        $actor, (string) $data['username'], (string) $data['password'],
                        (string) $data['password_confirmation'], (bool) ($data['consent_attested'] ?? false),
                    );
                    Notification::make()->success()->title('Пользователь создан')->send();
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $user]));
                }),
        ];
    }
}
