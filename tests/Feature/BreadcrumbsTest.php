<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_page_shows_section_breadcrumbs(): void
    {
        $admin = User::factory()->confirmed()->create([
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<nav class="page-breadcrumbs"',
            'href="'.route('home').'">Главная</a>',
            'href="'.route('admin.index').'">Админка</a>',
            'aria-current="page">Пользователи</span>',
            '</nav>',
        ], false);
    }
}
