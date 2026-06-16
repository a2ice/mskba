<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class AdminMenu implements MenuHandler
{
    use MenuHelper;

    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool}>
     */
    public function items(): array
    {
        return [
            [
                'label' => 'Дашборд',
                'description' => 'Сводная информация и статистика по проекту.',
                'url' => $this->routeUrl('admin.dashboard'),
                'active' => $this->isActiveRoute('admin.dashboard'),
                'visible' => true,
                'icon' => 'ti-dashboard',
                'data' => ['count' => 0], // TODO: добавить динамические данные
                'hideOnDashboard' => true, // специальный флаг для скрытия плитки на самой странице дашборда
            ],
            [
                'label' => 'Пользователи',
                'description' => 'Аккаунты, статусы и системные роли.',
                'url' => $this->routeUrl('admin.users'),
                'active' => $this->isActiveRoute('admin.users'),
                'visible' => true,
                'icon' => 'ti-users',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Площадки',
                'description' => 'Каталог площадок и модерация записей.',
                'url' => $this->routeUrl('admin.venues'),
                'active' => $this->isActiveRoute('admin.venues'),
                'visible' => true,
                'icon' => 'ti-building-stadium',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'События',
                'description' => 'Игры, тренировки и другие мероприятия.',
                'url' => $this->routeUrl('admin.events'),
                'active' => $this->isActiveRoute('admin.events'),
                'visible' => true,
                'icon' => 'ti-calendar-event',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Команды',
                'description' => 'Команды и будущие составы.',
                'url' => $this->routeUrl('admin.teams'),
                'active' => $this->isActiveRoute('admin.teams'),
                'visible' => true,
                'icon' => 'ti-shirt-sport',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Контент',
                'description' => 'Страницы и SEO-поля.',
                'url' => $this->routeUrl('admin.content'),
                'active' => $this->isActiveRoute('admin.content'),
                'visible' => true,
                'icon' => 'ti-file-text',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
            [
                'label' => 'Аудит',
                'description' => 'Журнал изменений ключевых сущностей.',
                'url' => $this->routeUrl('admin.audit'),
                'active' => $this->isActiveRoute('admin.audit'),
                'visible' => true,
                'icon' => 'ti-history',
                'data' => ['count' => 0],
            ],
            [
                'label' => 'Настройки',
                'description' => 'Базовые системные параметры.',
                'url' => $this->routeUrl('admin.settings'),
                'active' => $this->isActiveRoute('admin.settings'),
                'visible' => true,
                'icon' => 'ti-settings',
                'data' => ['count' => 0], // TODO: добавить динамическое количество
            ],
        ];
    }
}
