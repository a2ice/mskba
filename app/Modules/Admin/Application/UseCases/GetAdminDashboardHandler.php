<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Presentation\Navigation\MenuResolver;

final class GetAdminDashboardHandler
{
    public function __construct(
        private readonly MenuResolver $menuResolver,
        private readonly ListAdminContentPagesHandler $contentPages,
        private readonly GetAdminSettingsHandler $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // получаем AdminMenu и дополняем его данными для отображения счетчиков на карточках:
        $menuItems = $this->menuResolver->resolve('admin');

        foreach ($menuItems as &$item) {
            $item['data'] = ['count' => null];
            switch ($item['url']) {
                case route('admin.users'):
                    $item['data']['count'] = User::query()->count();
                    break;
                case route('admin.venues'):
                    $item['data']['count'] = Venue::query()->count();
                    break;
                case route('admin.venues.duplicates'):
                    $item['data']['count'] = VenueDuplicate::query()->count();
                    break;
                case route('admin.events'):
                    $item['data']['count'] = Event::query()->count();
                    break;
                case route('admin.teams'):
                    // Раздел пока работает на пустом placeholder-источнике.
                    $item['data']['count'] = 0;
                    break;
                case route('admin.content'):
                    $item['data']['count'] = count($this->contentPages->handle());
                    break;
                case route('admin.audit'):
                    $item['data']['count'] = AuditLog::query()->count();
                    break;
                case route('admin.settings'):
                    $item['data']['count'] = count($this->settings->handle());
                    break;
                case route('admin.telegram-chats'):
                    $item['data']['count'] = TelegramChat::query()->count();
                    break;
            }
        }
        unset($item);

        return [
            'tiles' => $menuItems,
        ];
    }
}
