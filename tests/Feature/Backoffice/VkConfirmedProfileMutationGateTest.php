<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use App\Modules\Vk\Application\UseCases\SyncVkProfileDataHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VkConfirmedProfileMutationGateTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserStatusEnum $status): User
    {
        $user = User::factory()->create(['status' => $status, 'system_role' => UserSystemRoleEnum::USER]);
        $user->createProfile([]);

        return $user;
    }

    private function vkIdentity(): VkUserIdentityDTO
    {
        return new VkUserIdentityDTO(
            id: 'vk-062-test',
            firstName: 'Новое',
            lastName: 'Имя',
            avatarUrl: null,
            rawData: [],
            gender: 'female',
            birthDate: '2000-04-01',
        );
    }

    public function test_vk_sync_preserves_all_confirmed_fields_even_if_empty(): void
    {
        $user = $this->user(UserStatusEnum::CONFIRMED);
        DB::transaction(fn () => app(SyncVkProfileDataHandler::class)->handle($user, $this->vkIdentity()));

        $profile = $user->fresh()->profile;
        $this->assertNull($profile->first_name);
        $this->assertNull($profile->last_name);
        $this->assertNull($profile->gender);
        $this->assertNull($profile->birth_date);
    }

    public function test_vk_registration_still_prefills_unconfirmed_identity(): void
    {
        $user = $this->user(UserStatusEnum::UNCONFIRMED);
        DB::transaction(fn () => app(SyncVkProfileDataHandler::class)->handle($user, $this->vkIdentity()));

        $profile = $user->fresh()->profile;
        $this->assertSame('Новое', $profile->first_name);
        $this->assertSame('Имя', $profile->last_name);
        $this->assertSame('2000-04-01', $profile->birth_date->toDateString());
    }
}
