<?php

namespace Tests\Feature;

use App\Modules\Uniform\Domain\Models\UniformDesign;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class UniformStudioFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function appTheme(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    private function input(): array
    {
        return [
            'name' => 'Форма чемпионов',
            'primary_color' => '#112233',
            'secondary_color' => '#FF8721',
            'sizes' => ['M', 'L', 'XL'],
            'logo_source' => 'none',
        ];
    }

    public function test_workshop_is_public_but_collection_requires_authentication(): void
    {
        $this->appTheme();
        $this->get('/uniforms/workshop')->assertOk()->assertSee('data-mskba-uniform-page', false);
        $this->getJson('/uniforms/options')->assertOk()->assertJsonPath('authenticated', false);
        $this->postJson('/uniforms/projects', $this->input())->assertUnauthorized();
        $this->getJson('/uniforms/projects')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_update_list_and_delete_their_own_design(): void
    {
        $this->appTheme();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get('/account/uniforms')->assertOk()->assertSee('data-mskba-uniform-list', false);

        $response = $this->postJson('/uniforms/projects', $this->input())->assertCreated()
            ->assertJsonPath('design.name', 'Форма чемпионов')
            ->assertJsonPath('design.primary_color', '#112233');
        $id = $response->json('design.id');
        $this->assertDatabaseHas('uniform_designs', ['id' => $id, 'user_id' => $user->id]);

        $this->getJson('/uniforms/projects')->assertOk()->assertJsonCount(1, 'designs');
        $this->getJson('/uniforms/projects/'.$id)->assertOk()->assertJsonPath('design.id', $id);
        $this->putJson('/uniforms/projects/'.$id, [
            ...$this->input(),
            'name' => 'Новая форма',
            'sizes' => ['S','M'],
        ])->assertOk()->assertJsonPath('design.name', 'Новая форма');

        $this->deleteJson('/uniforms/projects/'.$id)->assertOk();
        $this->assertSoftDeleted('uniform_designs', ['id' => $id]);
        $this->getJson('/uniforms/projects')->assertOk()->assertJsonCount(0, 'designs');
    }

    public function test_owner_isolation_and_team_logo_validation(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $design = UniformDesign::query()->create([
            'user_id' => $owner->id,
            'name' => 'Личный комплект',
            'primary_color' => '#112233',
            'secondary_color' => '#FF8721',
            'sizes' => ['L'],
            'logo_source' => 'none',
            'editor_version' => 1,
        ]);
        $this->actingAs($stranger);
        $this->getJson('/uniforms/projects/'.$design->id)->assertNotFound();
        $this->putJson('/uniforms/projects/'.$design->id, $this->input())->assertNotFound();
        $this->deleteJson('/uniforms/projects/'.$design->id)->assertNotFound();

        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'logo_source' => 'team',
            'logo_team_id' => 9999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('logo_team_id');

        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'logo_source' => 'custom',
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_custom_logo_is_private_and_deleted_with_the_design(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($owner);

        $result = $this->post('/uniforms/projects', [
            ...$this->input(),
            'logo_source' => 'custom',
            'logo' => UploadedFile::fake()->image('crest.png', 240, 240),
        ], ['Accept' => 'application/json'])->assertCreated();
        $id = $result->json('design.id');
        $design = UniformDesign::query()->findOrFail($id);
        $logo = $design->customLogo()->firstOrFail();
        $this->assertSame('uniform_design', $logo->mediable_type);
        $this->assertSame('image/webp', $logo->mime);
        Storage::disk('local')->assertExists($logo->path);
        $this->get('/uniforms/projects/'.$id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($stranger);
        $this->get('/uniforms/projects/'.$id.'/logo')->assertNotFound();
        $this->actingAs($owner);
        $this->deleteJson('/uniforms/projects/'.$id)->assertOk();
        Storage::disk('local')->assertMissing($logo->path);
    }

    public function test_invalid_sizes_colors_and_logo_source_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'primary_color' => 'javascript:alert(1)',
            'sizes' => ['M', 'FAKE'],
            'logo_source' => 'remote',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_color', 'sizes.1', 'logo_source']);
    }

    public function test_page_builder_preserves_the_public_workshop_trigger_without_inline_js(): void
    {
        $html = app(\App\Modules\Content\Application\Services\PageBuilderMarkup::class)->html(
            '<button type="submit" data-mskba-open-workshop="1"'
                .' data-mskba-workshop-context="home" onclick="alert(1)">Открыть мастерскую</button>',
        );
        $this->assertStringContainsString('data-mskba-open-workshop="1"', $html);
        $this->assertStringContainsString('data-mskba-workshop-context="home"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }
}
