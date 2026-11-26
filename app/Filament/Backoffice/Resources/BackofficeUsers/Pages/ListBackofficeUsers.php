<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\UseCases\AdminCreateUserHandler;
use App\Modules\Identity\Application\Services\TestAccountSequence;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ->modalDescription('Обычный аккаунт создаётся без контактов и подтверждения. Остальные данные заполняются в редакторе.')
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
                    Checkbox::make('is_test_account')
                        ->label('Тестовый аккаунт')->default(false)->live()
                        ->visible(fn (): bool => app(AdminCreateUserHandler::class)->canCreateTest(auth('web')->user()))
                        ->afterStateUpdated(function (?bool $state, Set $set): void {
                            $set('consent_attested', (bool) $state);
                            $set('test_confirmed', false);
                            $set('test_contact_verified', true);
                            $set('test_contact_type', 'email');
                            $set('test_contact_value', null);
                            $set('test_contact_suggestion', null);
                        }),
                    Checkbox::make('consent_attested')
                        ->label(fn (Get $get): string => $get('is_test_account')
                            ? 'Согласие на обработку (тестовое)'
                            : 'Подтверждаю, что получил согласие пользователя на обработку персональных данных')
                        ->helperText(fn (Get $get): string => $get('is_test_account')
                            ? 'Имитация согласия для тестирования, не реальное согласие пользователя.'
                            : 'Согласие: /personal-data-consent. Фиксируется подтверждение администратора.')
                        ->accepted(fn (Get $get): bool => ! $get('is_test_account'))
                        ->live()
                        ->afterStateUpdated(function (?bool $state, Set $set): void {
                            if (! $state) {
                                $set('test_confirmed', false);
                                $set('test_contact_value', null);
                                $set('test_contact_suggestion', null);
                            }
                        }),
                    Checkbox::make('test_confirmed')->label('Подтверждённый аккаунт')
                        ->default(false)->live()
                        ->visible(fn (Get $get): bool => (bool) $get('is_test_account'))
                        ->disabled(fn (Get $get): bool => ! $get('consent_attested'))
                        ->afterStateUpdated(function (?bool $state, Set $set): void {
                            $set('test_contact_type', 'email');
                            $set('test_contact_verified', true);
                            $sequence = app(TestAccountSequence::class);
                            $suggestion = $state ? $sequence->email($sequence->nextNumber()) : null;
                            $set('test_contact_value', $suggestion);
                            $set('test_contact_suggestion', $suggestion);
                        }),
                    Section::make('Контакт тестового аккаунта')
                        ->visible(fn (Get $get): bool => $get('is_test_account') && $get('consent_attested') && $get('test_confirmed'))
                        ->schema([
                            Hidden::make('test_contact_suggestion')->dehydrated(false),
                            Checkbox::make('test_contact_verified')->label('Подтверждённый контакт')
                                ->default(true)->live()
                                ->afterStateUpdated(function (?bool $state, Get $get, Set $set, $livewire): void {
                                    $value = trim((string) $get('test_contact_value'));
                                    if ($state && $value === '' && $get('test_contact_type') === 'email') {
                                        $sequence = app(TestAccountSequence::class);
                                        $suggestion = $sequence->email($sequence->nextNumber());
                                        $set('test_contact_value', $suggestion);
                                        $set('test_contact_suggestion', $suggestion);
                                    } elseif (! $state && ($value === '' || $value === $get('test_contact_suggestion'))) {
                                        $set('test_contact_value', null);
                                        $set('test_contact_suggestion', null);
                                        $livewire->dispatch('focus-test-contact');
                                    }
                                }),
                            Select::make('test_contact_type')->label('Тип контакта')
                                ->options(['email' => 'Email', 'phone' => 'Телефон'])
                                ->default('email')->required()->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $livewire): void {
                                    $set('test_contact_suggestion', null);
                                    $set('test_contact_value', null);
                                    if ($get('test_contact_type') === 'email' && $get('test_contact_verified')) {
                                        $sequence = app(TestAccountSequence::class);
                                        $suggestion = $sequence->email($sequence->nextNumber());
                                        $set('test_contact_value', $suggestion);
                                        $set('test_contact_suggestion', $suggestion);
                                    }
                                    $livewire->dispatch('focus-test-contact');
                                }),
                            TextInput::make('test_contact_value')->label('Контакт')
                                ->required()->maxLength(254)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    if ($state !== $get('test_contact_suggestion')) {
                                        $set('test_contact_suggestion', null);
                                    }
                                })
                                ->extraInputAttributes(['x-on:focus-test-contact.window' => '$nextTick(() => $el.focus())'])
                                ->helperText('Можно заменить предложенный email своим адресом или телефоном. Для реального контакта отметка подтверждения означает, что администратор удостоверился в его принадлежности тестировщику. Код не отправляется.'),
                        ]),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $user = app(AdminCreateUserHandler::class)->handle(
                        $actor, (string) $data['username'], (string) $data['password'],
                        (string) $data['password_confirmation'], (bool) ($data['consent_attested'] ?? false),
                        (bool) ($data['is_test_account'] ?? false), [
                            'confirmed' => (bool) ($data['test_confirmed'] ?? false),
                            'contact_type' => $data['test_contact_type'] ?? null,
                            'contact_value' => $data['test_contact_value'] ?? null,
                            'contact_verified' => (bool) ($data['test_contact_verified'] ?? false),
                        ],
                    );
                    Notification::make()->success()->title('Пользователь создан')->send();
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $user]));
                }),
        ];
    }
}
