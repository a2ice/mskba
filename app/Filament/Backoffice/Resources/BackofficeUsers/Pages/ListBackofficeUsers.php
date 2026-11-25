<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use App\Modules\Identity\Application\UseCases\AdminCreateUserHandler;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

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
                ->modalDescription('Будет создан неподтверждённый аккаунт без пароля и контактов. Остальные данные заполняются в редакторе.')
                ->modalSubmitActionLabel('Создать')
                ->schema([
                    TextInput::make('suggestion_email')
                        ->label('Email для подбора логина')
                        ->email()->maxLength(254)
                        ->helperText('Необязательно. Адрес используется только для подсказки и не сохраняется как контакт.')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            abort_unless(app(AdminCreateUserHandler::class)->canCreate(auth('web')->user()), 403);
                            if (filled($get('username')) || ! filter_var($state, FILTER_VALIDATE_EMAIL)) {
                                return;
                            }

                            $set('username', app(UsernameSuggestionService::class)->suggest($state));
                        })
                        ->dehydrated(false),
                    TextInput::make('username')
                        ->label('Логин')
                        ->required()
                        ->minLength(UsernameVO::MIN_LENGTH)
                        ->maxLength(UsernameVO::MAX_LENGTH),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $user = app(AdminCreateUserHandler::class)->handle($actor, (string) $data['username']);
                    Notification::make()->success()->title('Пользователь создан')->send();
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $user]));
                }),
        ];
    }
}
