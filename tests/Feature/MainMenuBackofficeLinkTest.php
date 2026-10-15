<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Navigation\MenuResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MainMenuBackofficeLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_are_linked_to_filament_instead_of_legacy_dashboard(): void
    {
        foreach ([UserSystemRoleEnum::ADMIN, UserSystemRoleEnum::SUPERADMIN] as $role) {
            $user = User::factory()->create([
                'system_role' => $role,
                'status' => UserStatusEnum::CONFIRMED,
            ]);

            $this->actingAs($user);

            $more = collect(app(MenuResolver::class)->resolve('main'))
                ->firstWhere('label', 'Еще')['children'];

            $adminLink = collect($more)->firstWhere('label', 'Админка');

            $this->assertNotNull($adminLink);
            $this->assertSame('/backoffice', $adminLink['url']);
            $this->assertTrue($adminLink['visible']);
        }
    }

    public function test_editor_retains_legacy_content_editor_link(): void
    {
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::EDITOR,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($user);

        $more = collect(app(MenuResolver::class)->resolve('main'))
            ->firstWhere('label', 'Еще')['children'];

        $this->assertNull(collect($more)->firstWhere('label', 'Админка'));
        $this->assertSame(route('admin.content'), collect($more)->firstWhere('label', 'Контент')['url']);
    }
}
