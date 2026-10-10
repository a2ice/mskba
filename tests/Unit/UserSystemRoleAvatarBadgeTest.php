<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use PHPUnit\Framework\TestCase;

final class UserSystemRoleAvatarBadgeTest extends TestCase
{
    public function test_only_human_staff_roles_have_distinct_avatar_markers(): void
    {
        $expected = [
            'superadmin' => ['initial' => 's', 'color' => '#7c3aed'],
            'admin' => ['initial' => 'a', 'color' => '#c2410c'],
            'moderator' => ['initial' => 'm', 'color' => '#1d4ed8'],
            'editor' => ['initial' => 'e', 'color' => '#0f766e'],
        ];

        foreach (UserSystemRoleEnum::cases() as $role) {
            $this->assertSame($expected[$role->value] ?? null, $role->avatarBadge());
        }

        $this->assertCount(4, array_unique(array_column($expected, 'color')));
    }
}
