<?php

namespace App\Filament\Backoffice\Resources\OperatorConversations\Pages;

use App\Filament\Backoffice\Resources\OperatorConversations\OperatorConversationResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\OperatorConversationMessaging;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;

final class ListOperatorConversations extends ListRecords
{
    protected static string $resource = OperatorConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startConversation')
                ->label('Начать диалог')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn (): bool => OperatorConversationResource::canViewAny())
                ->modalHeading('Сообщение пользователю')
                ->modalDescription('Отправьте сервисное сообщение любому зарегистрированному пользователю — даже если он офлайн. Рекламные обращения здесь запрещены.')
                ->schema([
                    Select::make('recipient_id')
                        ->label('Пользователь')
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search): array {
                            return User::query()
                                ->whereNull('canonical_user_id')
                                ->where('system_role', '!=', UserSystemRoleEnum::SYSTEM->value)
                                ->where('status', '!=', 'blocked')
                                ->whereKeyNot(auth('web')->user()->canonical()->id)
                                ->where('username', 'like', '%'.$search.'%')
                                ->orderBy('username')
                                ->limit(25)->pluck('username', 'id')->all();
                        })
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()
                            ->whereKey($value)->value('username'))
                        ->required(),
                    Textarea::make('body')
                        ->label('Текст сервисного сообщения')
                        ->maxLength(2000)->rows(4)->required(),
                ])
                ->modalSubmitActionLabel('Написать')
                ->action(function (array $data): void {
                    abort_unless(OperatorConversationResource::canViewAny(), 403);
                    $actor = auth('web')->user()->canonical();
                    $recipient = User::query()->findOrFail($data['recipient_id']);
                    $conversation = app(OperatorConversationMessaging::class)->start(
                        $actor, $recipient, $data['body'],
                    );
                    $this->redirect(OperatorConversationResource::getUrl('view', [
                        'record' => $conversation,
                    ]));
                }),
        ];
    }
}
