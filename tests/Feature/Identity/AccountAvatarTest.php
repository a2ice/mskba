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

    public function test_user_can_select_one_of_saved_avatars_as_active(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $first = $profile->media()->create($this->avatarAttributes('avatars/first.webp', true));
        $second = $profile->media()->create($this->avatarAttributes('avatars/second.webp'));
        Storage::disk('public')->put($first->path, 'first');
        Storage::disk('public')->put($second->path, 'second');

        $this->actingAs($user)
            ->from(route('account'))
            ->patch(route('account.avatar.activate', $second->id))
            ->assertRedirect(route('account'))
            ->assertSessionHas('avatar_status', 'Активный аватар изменён.');

        $this->assertFalse($first->refresh()->is_featured);
        $this->assertTrue($second->refresh()->is_featured);

        $this->get(route('account'))
            ->assertOk()
            ->assertSee('Сохранённые аватары')
            ->assertSee('partial-avatar text-center" data-tooltip-skip', false)
            ->assertSee(route('account.avatar.activate', $first->id), false)
            ->assertSee(route('account.avatar.destroy', $second->id), false);
    }

    public function test_deleting_active_avatar_activates_next_saved_avatar_and_removes_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $replacement = $profile->media()->create($this->avatarAttributes('avatars/replacement.webp'));
        $active = $profile->media()->create($this->avatarAttributes('avatars/active.webp', true));
        Storage::disk('public')->put($replacement->path, 'replacement');
        Storage::disk('public')->put($active->path, 'active');

        $this->actingAs($user)
            ->from(route('account'))
            ->delete(route('account.avatar.destroy', $active->id))
            ->assertRedirect(route('account'))
            ->assertSessionHas('avatar_status', 'Аватар удалён.');

        $this->assertSoftDeleted('media', ['id' => $active->id]);
        $this->assertTrue($replacement->refresh()->is_featured);
        Storage::disk('public')->assertMissing($active->path);
        Storage::disk('public')->assertExists($replacement->path);
    }

    public function test_user_cannot_activate_or_delete_another_users_avatar(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $ownerProfile = $owner->createProfile([]);
        $avatar = $ownerProfile->media()->create($this->avatarAttributes('avatars/owner.webp', true));
        Storage::disk('public')->put($avatar->path, 'owner');

        $otherUser = User::factory()->create();
        $otherUser->createProfile([]);

        $this->actingAs($otherUser)
            ->patch(route('account.avatar.activate', $avatar->id))
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->delete(route('account.avatar.destroy', $avatar->id))
            ->assertNotFound();

        $this->assertTrue($avatar->refresh()->is_featured);
        Storage::disk('public')->assertExists($avatar->path);
    }

    /**
     * @return array<string, mixed>
     */
    private function avatarAttributes(string $path, bool $active = false): array
    {
        return [
            'collection' => 'avatar',
            'source' => 'upload',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => 100,
            'is_featured' => $active,
        ];
    }
}
