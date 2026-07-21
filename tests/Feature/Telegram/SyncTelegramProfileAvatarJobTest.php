<?php

namespace Tests\Feature\Telegram;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncTelegramProfileAvatarJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_copies_telegram_avatar_to_local_storage(): void
    {
        Storage::fake('public');
        $photoUrl = 'https://cdn.telegram.test/avatar.jpg';
        $photo = UploadedFile::fake()->image('telegram.jpg', 400, 800);
        Http::fake([
            $photoUrl => Http::response(file_get_contents($photo->getPathname()), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 123,
            'photo_url' => $photoUrl,
        ]);

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class));

        $avatar = $profile->media()->where('collection', 'avatar')->sole();

        $this->assertSame('telegram', $avatar->source);
        $this->assertSame(hash('sha256', $photoUrl), $avatar->source_reference);
        $this->assertTrue($avatar->is_featured);
        Storage::disk('public')->assertExists($avatar->path);
    }

    public function test_telegram_sync_does_not_replace_manual_avatar(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $manualPhoto = UploadedFile::fake()->image('manual.jpg', 300, 300);
        $manualContents = file_get_contents($manualPhoto->getPathname());

        app(StoreProfileAvatarHandler::class)->handle($profile, $manualContents);

        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 456,
            'photo_url' => 'https://cdn.telegram.test/new-avatar.jpg',
        ]);

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class));

        $this->assertSame('upload', $profile->activeAvatar()->sole()->source);
        $this->assertSame(1, $profile->media()->where('collection', 'avatar')->count());
        Http::assertNothingSent();
    }
}
