<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class TeamLogoPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_preset_is_saved_as_an_independent_logo_and_can_be_deleted(): void
    {
        Storage::fake('public');
        $asset = public_path('images/tournament-team-logos/crest-03.webp');
        $originalHash = hash_file('sha256', $asset);
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($user)->post(route('teams.store'), [
            'name' => 'Команда с эмблемой',
            'logo_preset' => 'crest-03',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда с эмблемой')->firstOrFail();
        $logo = $team->logo()->firstOrFail();
        $this->assertSame('preset', $logo->source);
        $this->assertSame('image/webp', $logo->mime);
        $this->assertStringStartsWith("teams/{$team->id}/", $logo->path);
        Storage::disk('public')->assertExists($logo->path);
        $this->assertTrue(ContractMembership::query()->where('scope_type', 'team')->where('scope_id', $team->id)->where('user_id', $user->id)->exists());

        $this->delete(route('teams.logo.destroy', $team->routeIdentifier()))->assertRedirect();
        $this->assertNull($team->fresh()->logo);
        Storage::disk('public')->assertMissing($logo->path);
        $this->assertSame($originalHash, hash_file('sha256', $asset));
    }

    public function test_arbitrary_preset_paths_are_rejected_before_creating_a_team(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($user)->post(route('teams.store'), [
            'name' => 'Недопустимая эмблема',
            'logo_preset' => '../../.env',
        ])->assertSessionHasErrors('logo_preset');

        $this->assertDatabaseMissing('teams', ['name' => 'Недопустимая эмблема']);
    }

    public function test_logo_storage_failure_rolls_back_the_team_and_owner_contract(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('public', $disk);
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post(route('teams.store'), [
                'name' => 'Не сохранённая команда',
                'logo_preset' => 'crest-00',
            ]);
            $this->fail('Ожидалась ошибка сохранения логотипа.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Не удалось сохранить логотип команды.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('teams', ['name' => 'Не сохранённая команда']);
        $this->assertSame(0, ContractMembership::query()->where('scope_type', 'team')->count());
    }
}
