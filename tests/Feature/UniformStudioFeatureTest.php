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
            'sleeve_style' => 'sleeveless',
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
            ->assertJsonPath('design.primary_color', '#112233')
            ->assertJsonPath('design.sleeve_style', 'sleeveless');
        $id = $response->json('design.id');
        $this->assertDatabaseHas('uniform_designs', ['id' => $id, 'user_id' => $user->id]);

        $this->getJson('/uniforms/projects')->assertOk()->assertJsonCount(1, 'designs');
        $this->getJson('/uniforms/projects/'.$id)->assertOk()->assertJsonPath('design.id', $id);
        $this->putJson('/uniforms/projects/'.$id, [
            ...$this->input(),
            'name' => 'Новая форма',
            'sizes' => ['S','M'],
            'sleeve_style' => 'sleeved',
        ])->assertOk()->assertJsonPath('design.name', 'Новая форма')
            ->assertJsonPath('design.sleeve_style', 'sleeved');

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
            'sleeve_style' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_color', 'sizes.1', 'logo_source', 'sleeve_style']);
    }


    public function test_four_view_svg_options_round_trip_without_a_secondary_color(): void
    {
        $this->actingAs(User::factory()->create());
        $input = [
            ...$this->input(),
            'secondary_color' => null,
            'jersey_number' => 999,
            'number_outline' => true,
            'number_color' => '#123ABC',
            'number_outline_color' => '#F1F2F3',
            'logo_front_position' => 'right',
            'logo_back_position' => 'below',
        ];

        $created = $this->postJson('/uniforms/projects', $input)->assertCreated()
            ->assertJsonPath('design.secondary_color', null)
            ->assertJsonPath('design.jersey_number', 999)
            ->assertJsonPath('design.number_color', '#123ABC')
            ->assertJsonPath('design.number_outline', true)
            ->assertJsonPath('design.number_outline_color', '#F1F2F3')
            ->assertJsonPath('design.logo_front_position', 'right')
            ->assertJsonPath('design.logo_back_position', 'below');

        $id = $created->json('design.id');
        $this->getJson('/uniforms/projects/'.$id)->assertOk()
            ->assertJsonPath('design.jersey_number', 999)
            ->assertJsonPath('design.logo_front_position', 'right');

        $this->putJson('/uniforms/projects/'.$id, [
            ...$this->input(),
            'jersey_number' => 1,
            'number_outline' => false,
            'logo_front_position' => 'none',
            'logo_back_position' => 'above',
        ])->assertOk()
            ->assertJsonPath('design.jersey_number', 1)
            ->assertJsonPath('design.number_outline', false)
            ->assertJsonPath('design.number_outline_color', null)
            ->assertJsonPath('design.logo_front_position', 'none');

        $this->assertDatabaseHas('uniform_designs', [
            'id' => $id,
            'jersey_number' => 1,
            'logo_front_position' => 'none',
            'logo_back_position' => 'above',
        ]);
    }

    public function test_svg_number_and_logo_position_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'jersey_number' => 0,
            'number_color' => 'red',
            'number_outline_color' => 'url(#svg)',
            'logo_front_position' => 'center',
            'logo_back_position' => 'sleeve',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'jersey_number', 'number_color', 'number_outline_color',
            'logo_front_position', 'logo_back_position',
        ]);
        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'jersey_number' => 1000,
        ])->assertUnprocessable()->assertJsonValidationErrors('jersey_number');

        // Existing clients still get safe defaults when new fields are omitted.
        $this->postJson('/uniforms/projects', $this->input())->assertCreated()
            ->assertJsonPath('design.jersey_number', 1)
            ->assertJsonPath('design.number_outline', false);
    }

    public function test_number_toggle_and_independent_drag_coordinates_are_persisted(): void
    {
        $this->actingAs(User::factory()->create());
        $created = $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'jersey_number' => 23,
            'number_enabled' => false,
            'jersey_number_x' => 244,
            'jersey_number_y' => 412,
            'jersey_number_scale' => 0.65,
            'shorts_number_x' => 196,
            'shorts_number_y' => 735,
            'shorts_number_scale' => 1.15,
        ])->assertCreated()
            ->assertJsonPath('design.jersey_number', 23)
            ->assertJsonPath('design.number_enabled', false)
            ->assertJsonPath('design.jersey_number_x', 244)
            ->assertJsonPath('design.jersey_number_y', 412)
            ->assertJsonPath('design.jersey_number_scale', 0.65)
            ->assertJsonPath('design.shorts_number_x', 196)
            ->assertJsonPath('design.shorts_number_y', 735)
            ->assertJsonPath('design.shorts_number_scale', 1.15);

        $id = $created->json('design.id');
        $this->getJson('/uniforms/projects/'.$id)->assertOk()
            ->assertJsonPath('design.number_enabled', false)
            ->assertJsonPath('design.jersey_number_x', 244);

        // Enabling the number does not erase either set of saved coordinates.
        $this->putJson('/uniforms/projects/'.$id, [
            ...$this->input(),
            'jersey_number' => 23,
            'number_enabled' => true,
            'jersey_number_x' => 244,
            'jersey_number_y' => 412,
            'jersey_number_scale' => 0.65,
            'shorts_number_x' => 196,
            'shorts_number_y' => 735,
            'shorts_number_scale' => 1.15,
        ])->assertOk()
            ->assertJsonPath('design.number_enabled', true)
            ->assertJsonPath('design.jersey_number_scale', 0.65)
            ->assertJsonPath('design.shorts_number_scale', 1.15);
    }

    public function test_number_drag_rejects_out_of_bounds_and_old_clients_receive_defaults(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'jersey_number_x' => 900,
            'shorts_number_y' => 10,
            'jersey_number_scale' => 2.5,
            'shorts_number_scale' => 0.1,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'jersey_number_x', 'shorts_number_y', 'jersey_number_scale',
            'shorts_number_scale',
        ]);

        $this->postJson('/uniforms/projects', $this->input())->assertCreated()
            ->assertJsonPath('design.number_enabled', true)
            ->assertJsonPath('design.jersey_number_x', 225)
            ->assertJsonPath('design.jersey_number_y', 394)
            ->assertJsonPath('design.shorts_number_x', 178)
            ->assertJsonPath('design.shorts_number_y', 755);
    }

    public function test_workshop_has_single_size_dropdown_and_accessible_cyclic_side_arrows(): void
    {
        $workshop = file_get_contents(resource_path('themes/mskba_app/js/uniforms/UniformWorkshop.vue'));
        $views = file_get_contents(resource_path('themes/mskba_app/js/uniforms/uniform-workshop-navigation.js'));

        $this->assertIsString($workshop);
        $this->assertIsString($views);
        $this->assertStringContainsString('const selectedSize = computed({', $workshop);
        $this->assertStringContainsString('form.sizes = [selectedSize.value]', $workshop);
        $this->assertStringContainsString('<select v-model="selectedSize"', $workshop);
        $this->assertStringContainsString(':size="selectedSize"', $workshop);
        $this->assertStringNotContainsString('mskba-uniform-editor__size-grid', $workshop);
        $this->assertStringNotContainsString('mskba-uniform-editor__preview-size', $workshop);
        $this->assertStringNotContainsString('mskba-uniform-editor__views', $workshop);
        $this->assertStringContainsString('@click="changeView(1)"', $workshop);
        $this->assertStringContainsString('@click="changeView(-1)"', $workshop);
        $this->assertStringContainsString('@click="resetNumberPosition"', $workshop);
        $this->assertStringContainsString("['front', 'left', 'rear', 'right']", $views);
    }

    public function test_one_selected_uniform_size_round_trips_in_legacy_array_api(): void
    {
        $this->actingAs(User::factory()->create());

        $created = $this->postJson('/uniforms/projects', [
            ...$this->input(),
            'sizes' => ['XXL'],
        ])->assertCreated()->assertJsonPath('design.sizes', ['XXL']);

        $this->putJson('/uniforms/projects/'.$created->json('design.id'), [
            ...$this->input(),
            'sizes' => ['XS'],
        ])->assertOk()->assertJsonPath('design.sizes', ['XS']);
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
