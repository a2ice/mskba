<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountAvatarAjaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_activate_and_delete_return_fresh_json_without_redirecting(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->createProfile([]);
        $this->actingAs($user)->withHeader('Accept', 'application/json');

        $this->post(route('account.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('first.png', 600, 400),
        ])->assertOk()
            ->assertJsonPath('message', 'Аватар обновлён.')
            ->assertJsonCount(1, 'avatars')
            ->assertJsonPath('avatars.0.is_featured', true);

        $first = $profile->avatars()->firstOrFail();
        $this->post(route('account.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('second.jpg', 1000, 800),
        ])->assertOk()
            ->assertJsonCount(2, 'avatars')
            ->assertJsonPath('avatars.0.is_featured', true);

        $second = $profile->avatars()->firstOrFail();
        $this->assertNotSame($first->id, $second->id);

        $this->patch(route('account.avatar.activate', $first->id))
            ->assertOk()
            ->assertJsonPath('message', 'Активный аватар изменён.')
            ->assertJsonPath('avatar_url', $first->publicUrl())
            ->assertJsonCount(2, 'avatars');

        $this->delete(route('account.avatar.destroy', $first->id))
            ->assertOk()
            ->assertJsonPath('message', 'Аватар удалён.')
            ->assertJsonPath('avatar_url', $second->publicUrl())
            ->assertJsonCount(1, 'avatars');

        $this->delete(route('account.avatar.destroy', $second->id))
            ->assertOk()
            ->assertJsonPath('avatar_url', null)
            ->assertJsonCount(0, 'avatars');

        $this->assertSame(0, $profile->avatars()->count());
    }

    public function test_validation_and_missing_avatars_are_json_errors(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);
        $this->actingAs($user)->withHeader('Accept', 'application/json');

        $this->post(route('account.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('too-large.jpg')->size(10241),
        ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->delete(route('account.avatar.destroy', 999999))
            ->assertNotFound();
    }
}
