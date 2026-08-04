<?php

namespace Tests\Feature\Telegram;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class), app(TelegramBotApiClient::class));

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

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class), app(TelegramBotApiClient::class));

        $this->assertSame('upload', $profile->activeAvatar()->sole()->source);
        $this->assertSame(1, $profile->media()->where('collection', 'avatar')->count());
        Http::assertNothingSent();
    }

    public function test_telegram_sync_does_not_restore_avatar_deleted_by_user(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $photoUrl = 'https://cdn.telegram.test/deleted-avatar.jpg';
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $avatar = $profile->media()->create([
            'collection' => 'avatar',
            'source' => 'telegram',
            'source_reference' => hash('sha256', $photoUrl),
            'disk' => 'public',
            'path' => 'avatars/deleted.webp',
            'mime' => 'image/webp',
            'size' => 100,
            'is_featured' => true,
        ]);
        $avatar->delete();

        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 789,
            'photo_url' => $photoUrl,
        ]);

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class), app(TelegramBotApiClient::class));

        $this->assertSame(0, $profile->media()->where('collection', 'avatar')->count());
        Http::assertNothingSent();
    }

    public function test_job_fetches_profile_photo_through_bot_api_when_mini_app_has_no_photo_url(): void
    {
        Storage::fake('public');
        config(['telegram.bot_token' => '123456:test-token', 'telegram.api_base_url' => 'https://api.telegram.test']);
        $photo = UploadedFile::fake()->image('telegram-api.jpg', 400, 400);
        Http::fake([
            'https://api.telegram.test/bot123456:test-token/getUserProfilePhotos' => Http::response([
                'ok' => true,
                'result' => ['photos' => [[
                    ['file_id' => 'small', 'file_unique_id' => 'same-photo', 'width' => 160, 'height' => 160],
                    ['file_id' => 'large', 'file_unique_id' => 'same-photo', 'width' => 640, 'height' => 640],
                ]]],
            ]),
            'https://api.telegram.test/bot123456:test-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/avatar.jpg'],
            ]),
            'https://api.telegram.test/file/bot123456:test-token/photos/avatar.jpg' => Http::response(file_get_contents($photo->getPathname()), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 987654,
            'photo_url' => null,
        ]);

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class), app(TelegramBotApiClient::class));

        $avatar = $profile->media()->where('collection', 'avatar')->sole();
        $this->assertSame('telegram', $avatar->source);
        $this->assertSame(hash('sha256', 'telegram-profile:987654:same-photo'), $avatar->source_reference);
        Storage::disk('public')->assertExists($avatar->path);
    }

    public function test_job_prefers_bot_api_when_public_photo_url_is_present(): void
    {
        Storage::fake('public');
        config(['telegram.bot_token' => '123456:test-token', 'telegram.api_base_url' => 'https://api.telegram.test']);
        $photo = UploadedFile::fake()->image('telegram-api.jpg', 400, 400);
        Http::fake([
            'https://api.telegram.test/bot123456:test-token/getUserProfilePhotos' => Http::response([
                'ok' => true,
                'result' => ['photos' => [[
                    ['file_id' => 'large', 'file_unique_id' => 'bot-photo', 'width' => 640, 'height' => 640],
                ]]],
            ]),
            'https://api.telegram.test/bot123456:test-token/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/avatar.jpg'],
            ]),
            'https://api.telegram.test/file/bot123456:test-token/photos/avatar.jpg' => Http::response(file_get_contents($photo->getPathname()), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 987655,
            'photo_url' => 'https://t.me/i/userpic/320/unreachable.svg',
        ]);

        (new SyncTelegramProfileAvatarJob($account->id))->handle(app(StoreProfileAvatarHandler::class), app(TelegramBotApiClient::class));

        $avatar = $profile->media()->where('collection', 'avatar')->sole();
        $this->assertSame(hash('sha256', 'telegram-profile:987655:bot-photo'), $avatar->source_reference);
        Http::assertNotSent(fn ($request) => $request->url() === $account->photo_url);
    }

    public function test_backfill_command_queues_only_telegram_accounts_without_active_avatar(): void
    {
        Queue::fake();
        $withoutAvatar = User::factory()->create();
        $withoutAvatar->createProfile([]);
        $missingAccount = TelegramAccount::query()->create(['user_id' => $withoutAvatar->id, 'telegram_user_id' => 111]);

        $withAvatar = User::factory()->create();
        $profile = $withAvatar->createProfile([]);
        $profile->media()->create([
            'collection' => 'avatar', 'source' => 'upload', 'disk' => 'public',
            'path' => 'avatars/existing.webp', 'mime' => 'image/webp', 'size' => 100, 'is_featured' => true,
        ]);
        $existingAccount = TelegramAccount::query()->create(['user_id' => $withAvatar->id, 'telegram_user_id' => 222]);

        $this->artisan('telegram:sync-profile-avatars --missing')->assertSuccessful();

        Queue::assertPushed(fn (SyncTelegramProfileAvatarJob $job) => $job->telegramAccountId === $missingAccount->id);
        Queue::assertNotPushed(fn (SyncTelegramProfileAvatarJob $job) => $job->telegramAccountId === $existingAccount->id);
    }
}
