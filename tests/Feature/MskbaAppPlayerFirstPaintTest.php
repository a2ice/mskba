<?php

namespace Tests\Feature;

use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MskbaAppPlayerFirstPaintTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        $this->switchTheme('mskba_app');
    }

    protected function tearDown(): void
    {
        $this->switchTheme($this->previousTheme);
        parent::tearDown();
    }

    private function switchTheme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    private function player(): User
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => null,
        ]);
        $user->profile()->create(['gender' => UserGenderEnum::MALE]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $user->playerProfile()->create([
            'height_cm' => 196,
            'weight_kg' => 94,
            'body_type' => 'muscular',
            'extra' => ['character' => []],
        ]);

        return $user;
    }

    private function completedGeneration(User $user): PlayerCharacterGeneration
    {
        $id = (string) Str::uuid();

        return PlayerCharacterGeneration::query()->create([
            'public_id' => $id,
            'user_id' => $user->id,
            'provider' => 'github_openai',
            'status' => PlayerCharacterGenerationStatusEnum::COMPLETED,
            'reference_media_ids' => [],
            'payload_snapshot' => [],
            'result_disk' => 'local',
            'result_path' => 'player-character-generations/'.$id.'/result.png',
            'result_mime' => 'image/png',
            'result_size' => 123,
            'completed_at' => now(),
        ]);
    }

    public function test_first_html_contains_correct_figure_height_and_width_without_avatar_loader(): void
    {
        $user = $this->player();
        $html = $this->actingAs($user)
            ->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()->getContent();

        $expectedHeight = round(196 / 225 * 100, 4);
        $expectedWidth = min(1.4, max(0.78, 1 + (94 / (1.96 ** 2) - 23) * 0.009 + 0.12));
        $this->assertStringContainsString(
            'style="--figure-height:'.$expectedHeight.'%;--figure-width:'.number_format($expectedWidth, 4, '.', '').'"',
            $html,
        );
        $this->assertStringContainsString('data-initial-avatar-id=""', $html);
        $this->assertMatchesRegularExpression('/data-player-figure-loading[^>]*hidden/', $html);
        $this->assertStringContainsString('data-player-height-marker', $html);
        $this->assertStringContainsString('>196 см</span>', $html);
    }

    public function test_selected_primary_generated_avatar_is_in_server_html_before_library_fetch(): void
    {
        $user = $this->player();
        $older = $this->completedGeneration($user);
        $this->travel(60)->seconds();
        $this->completedGeneration($user);
        $profile = $user->playerProfile()->firstOrFail();
        $extra = $profile->extra;
        $extra['character']['primary_generation_id'] = $older->public_id;
        $profile->forceFill(['extra' => $extra])->save();

        $html = $this->actingAs($user)
            ->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()->getContent();

        $imageUrl = route('account.player-character.generations.image', [
            'generation' => $older->public_id,
        ]).'?v='.$older->updated_at?->getTimestamp();
        $this->assertStringContainsString('data-initial-avatar-id="'.$older->public_id.'"', $html);
        $this->assertStringContainsString('src="'.$imageUrl.'"', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*class="is-generated"[^>]*data-player-figure/', $html);
        $this->assertStringContainsString('data-player-figure-loading', $html);
        $this->assertMatchesRegularExpression('/data-player-avatar-empty\\s+hidden/', $html);
    }

    public function test_saved_face_reference_is_rendered_in_html_with_private_url_and_no_initial_ajax_needed(): void
    {
        Storage::fake('local');
        $user = $this->player();

        $upload = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->image('front.jpg', 1200, 900),
            ])->assertOk();

        $mediaId = $upload->json('media_id');
        $html = $this->actingAs($user)
            ->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()->getContent();

        $url = route('account.player-character.face-reference', ['slot' => 'front']).'?v='.$mediaId;
        $this->assertStringContainsString('src="'.$url.'"', $html);
        $this->assertMatchesRegularExpression('/class="app-player-workspace__face-upload has-file[^"]*"[^>]*data-face-upload="front"/', $html);
        $this->assertStringContainsString('Заменить фото', $html);
        $this->assertStringContainsString('data-face-upload="left"', $html);
        $this->assertStringContainsString('data-face-placeholder=', $html);
    }

    public function test_client_avoids_initial_face_fetch_and_waits_for_avatar_image_load(): void
    {
        $js = file_get_contents(resource_path('themes/mskba_app/js/player-profile-workspace.js'));
        $faces = file_get_contents(resource_path('themes/mskba_app/js/player-generation-options.js'));
        $html = file_get_contents(resource_path('themes/mskba_app/views/pages/account/partials/player-profile-workspace.blade.php'));

        $this->assertStringContainsString("new Image()", $js);
        $this->assertStringContainsString("data-player-figure-loading", $html);
        $this->assertStringContainsString('setFigureLoading(false)', $js);
        // A single refresh remains after successful generation; there is no initial fetch.
        $this->assertSame(1, substr_count($faces, 'void refreshFaceReferences().catch(() => {});'));
        $this->assertStringContainsString('First paint already includes private saved references', $faces);
        $this->assertStringContainsString('form.sizes', file_get_contents(resource_path('themes/mskba_app/js/uniforms/UniformWorkshop.vue')));
    }
}
