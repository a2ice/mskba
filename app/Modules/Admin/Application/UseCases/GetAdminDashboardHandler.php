<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Presentation\Navigation\MenuResolver;

final class GetAdminDashboardHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // получаем AdminMenu и дополняем его данными для отображения счетчиков на карточках:
        $menuItems = app(MenuResolver::class)
            ->resolve('admin');

        foreach ($menuItems as &$item) {
            $item['data'] = ['count' => 0]; // инициализируем поле data для счетчика
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
                case route('admin.audit'):
                    $item['data']['count'] = AuditLog::query()->count();
                    break;
            }
        }

        return [
            'tiles' => $menuItems,
        ];
    }
}
