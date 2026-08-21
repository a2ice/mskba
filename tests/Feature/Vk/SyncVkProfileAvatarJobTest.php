<?php

namespace Tests\Feature\Vk;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Modules\Vk\Infrastructure\Jobs\SyncVkProfileAvatarJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SyncVkProfileAvatarJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_copies_vk_avatar_to_local_storage(): void
    {
        Storage::fake('public');
        $avatarUrl = 'https://cdn.vk.test/avatar.jpg';
        $photo = UploadedFile::fake()->image('vk.jpg', 400, 800);
        Http::fake([
            $avatarUrl => Http::response(file_get_contents($photo->getPathname()), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $account = VkAccount::query()->create([
            'user_id' => $user->id,
            'vk_user_id' => '123',
            'avatar_url' => $avatarUrl,
        ]);

        (new SyncVkProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class));

        $avatar = $profile->media()->where('collection', 'avatar')->sole();
        $this->assertSame('vk', $avatar->source);
        $this->assertSame(hash('sha256', $avatarUrl), $avatar->source_reference);
        $this->assertTrue($avatar->is_featured);
        Storage::disk('public')->assertExists($avatar->path);
    }

    public function test_vk_sync_does_not_replace_manual_avatar(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $manualPhoto = UploadedFile::fake()->image('manual.jpg', 300, 300);

        app(StoreProfileAvatarHandler::class)->handle($profile, file_get_contents($manualPhoto->getPathname()));

        $account = VkAccount::query()->create([
            'user_id' => $user->id,
            'vk_user_id' => '456',
            'avatar_url' => 'https://cdn.vk.test/new-avatar.jpg',
        ]);

        (new SyncVkProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class));

        $this->assertSame('upload', $profile->activeAvatar()->sole()->source);
        $this->assertSame(1, $profile->media()->where('collection', 'avatar')->count());
        Http::assertNothingSent();
    }
}
