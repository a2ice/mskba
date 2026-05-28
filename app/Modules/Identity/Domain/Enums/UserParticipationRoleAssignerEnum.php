<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserParticipationRoleAssignerEnum: string
{
    // Роль назначена конкретным пользователем вручную.
    case USER = 'user';

    // Роль назначена автоматически flow или прикладной логикой проекта.
    case FLOW = 'flow';

    // Роль создана сидером или базовой инициализацией данных.
    case SEEDER = 'seeder';

    // Нестандартный источник назначения, который пока не выделен в отдельный тип.
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Пользователь',
            self::FLOW => 'Бизнес-логика',
            self::SEEDER => 'Seeder',
            self::OTHER => 'Другое',
        };
    }
}
