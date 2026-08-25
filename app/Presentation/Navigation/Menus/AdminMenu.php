<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final class AdminMenu implements MenuHandler
{
    use MenuHelper;

    public function __construct(private readonly FeatureFlags $features) {}

    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool}>
     */
    public function items(): array
    {
        $user = request()->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $canManageContent = $user?->can('manage-content') ?? false;
        $canManageUserDuplicates = $user?->can('manage-users-as-superadmin') ?? false;

        return [
            [
                'label' => 'Дашборд',
                'description' => 'Сводная информация и статистика по проекту.',
                'url' => $this->routeUrl('admin.dashboard'),
                'active' => $this->isActiveRoute('admin.dashboard'),
                'visible' => $isAdmin,
                'icon' => 'ti-dashboard',
                'data' => ['count' => 0], // TODO: добавить динамические данные
                'hideOnDashboard' => true, // специальный флаг для скрытия плитки на самой странице дашборда
            ],
            [
                'label' => 'Пользователи',
                'description' => 'Аккаунты, статусы и системные роли.',
                'url' => $this->routeUrl('admin.users'),
                'active' => $this->isActiveRoute('admin.users'),
                'visible' => $isAdmin,
                'icon' => 'ti-users',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Дубли пользователей',
                'description' => 'Кандидаты на объединение пользовательских аккаунтов.',
                'url' => $this->routeUrl('admin.users.duplicates'),
                'active' => $this->isActiveRoute('admin.users.duplicates, admin.users.duplicates.*'),
                'visible' => $canManageUserDuplicates,
                'icon' => 'ti-users-group',
                'data' => ['count' => 0],
            ],
            [
                'label' => 'Площадки',
                'description' => 'Каталог площадок и модерация записей.',
                'url' => $this->routeUrl('admin.venues'),
                'active' => $this->isActiveRoute('admin.venues, admin.venues.*'),
                'visible' => $isAdmin,
                'icon' => 'ti-building-stadium',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Дубли площадок',
                'description' => 'Кандидаты на объединение площадок.',
                'url' => $this->routeUrl('admin.venues.duplicates'),
                'active' => $this->isActiveRoute('admin.venues.duplicates, admin.venues.duplicates.*'),
                'visible' => $isAdmin,
                'icon' => 'ti-copy-check',
                'data' => ['count' => 0],
            ],
            [
                'label' => 'Владение площадками',
                'description' => 'Заявки на подтверждение коммерческого владельца.',
                'url' => $this->routeUrl('admin.venue-ownership-claims.index'),
                'active' => $this->isActiveRoute('admin.venue-ownership-claims.*'),
                'visible' => $isAdmin && $this->features->enabled(VenueRentalFeature::RENTAL_FLOW),
                'icon' => 'ti-certificate',
                'data' => ['count' => 0],
            ],
            [
                'label' => 'Мероприятия',
                'description' => 'Игры, тренировки и игровые тренировки.',
                'url' => $this->routeUrl('admin.events'),
                'active' => $this->isActiveRoute('admin.events'),
                'visible' => $isAdmin,
                'icon' => 'ti-calendar-event',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Команды',
                'description' => 'Команды и будущие составы.',
                'url' => $this->routeUrl('admin.teams'),
                'active' => $this->isActiveRoute('admin.teams'),
                'visible' => $isAdmin,
                'icon' => 'ti-shirt-sport',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Контент',
                'description' => 'Материалы, лента новостей и Telegram.',
                'url' => $this->routeUrl('admin.content'),
                'active' => $this->isActiveRoute('admin.content, admin.content.*'),
                'visible' => $canManageContent,
                'icon' => 'ti-file-text',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Аудит',
                'description' => 'Журнал изменений ключевых сущностей.',
                'url' => $this->routeUrl('admin.audit'),
                'active' => $this->isActiveRoute('admin.audit'),
                'visible' => $isAdmin,
                'icon' => 'ti-history',
                'data' => ['count' => 0],
            ],
            [
                'label' => 'Настройки',
                'description' => 'Базовые системные параметры.',
                'url' => $this->routeUrl('admin.settings'),
                'active' => $this->isActiveRoute('admin.settings'),
                'visible' => $isAdmin,
                'icon' => 'ti-settings',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Telegram-чаты',
                'description' => 'Чаты для публикации опросов и согласований.',
                'url' => $this->routeUrl('admin.telegram-chats'),
                'active' => $this->isActiveRoute('admin.telegram-chats, admin.telegram-chats.*'),
                'visible' => $isAdmin,
                'icon' => 'ti-brand-telegram',
                'data' => ['count' => 0],
            ],
        ];
    }
}
