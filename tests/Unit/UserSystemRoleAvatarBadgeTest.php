<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use PHPUnit\Framework\TestCase;

final class UserSystemRoleAvatarBadgeTest extends TestCase
{
    public function test_only_human_staff_roles_have_distinct_avatar_border_colors(): void
    {
        $expected = [
            'superadmin' => '#7c3aed',
            'admin' => '#c2410c',
            'manager' => '#0f766e',
            'moderator' => '#1d4ed8',
            'editor' => '#0f766e',
        ];

        foreach (UserSystemRoleEnum::cases() as $role) {
            $this->assertSame($expected[$role->value] ?? null, $role->avatarBorderColor());
        }

        $this->assertCount(4, array_unique(array_values($expected)));
    }
}
