<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;

final class GetAdminDashboardHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // получаем AdminMenu и дополняем его данными для отображения счетчиков на карточках:
        $menuItems = app(\App\Presentation\Navigation\MenuResolver::class)
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
