<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_normalized_profile_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->createProfile(['first_name' => 'Анна']);

        $this->actingAs($user)
            ->post(route('account.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('portrait.png', 1200, 600),
            ])
            ->assertRedirect()
            ->assertSessionHas('avatar_status', 'Аватар обновлён.');

        $avatar = $profile->media()->where('collection', 'avatar')->sole();

        $this->assertTrue($avatar->is_featured);
        $this->assertSame('upload', $avatar->source);
        $this->assertSame('image/webp', $avatar->mime);
        Storage::disk('public')->assertExists($avatar->path);

        $info = getimagesizefromstring(Storage::disk('public')->get($avatar->path));

        $this->assertIsArray($info);
        $this->assertSame(200, $info[0]);
        $this->assertSame(100, $info[1]);
        $this->assertSame('image/webp', $info['mime']);

        $this->get(route('account'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($avatar->path), false)
            ->assertSee('Загрузить новый аватар');
    }

    public function test_only_three_avatars_are_kept_and_only_latest_is_active(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $paths = [];

        foreach (range(1, 4) as $number) {
            $this->actingAs($user)
                ->post(route('account.avatar.store'), [
                    'avatar' => UploadedFile::fake()->image("avatar-{$number}.jpg", 300, 300),
                ])
                ->assertRedirect();

            $paths[] = $profile->media()->where('collection', 'avatar')->where('is_featured', true)->sole()->path;
        }

        $avatars = $profile->media()->where('collection', 'avatar')->latest('id')->get();

        $this->assertCount(3, $avatars);
        $this->assertSame(1, $avatars->where('is_featured', true)->count());
        $this->assertSame($paths[3], $avatars->first()->path);
        Storage::disk('public')->assertMissing($paths[0]);

        foreach ($avatars as $avatar) {
            Storage::disk('public')->assertExists($avatar->path);
        }
    }

    public function test_avatar_upload_rejects_unsupported_file(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        $this->actingAs($user)
            ->from(route('account'))
            ->post(route('account.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('avatar.svg', 10, 'image/svg+xml'),
            ])
            ->assertRedirect(route('account'))
            ->assertSessionHasErrors('avatar');
    }
}
