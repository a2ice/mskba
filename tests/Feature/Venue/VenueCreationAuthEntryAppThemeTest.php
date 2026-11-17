<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCreationAuthEntryAppThemeTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        View::getFinder()->flush();
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        View::getFinder()->flush();
        parent::tearDown();
    }

    public function test_guest_sees_real_auth_entry_and_return_to_exact_create_url(): void
    {
        $response = $this->get('/venues/create?from=catalog&sort=new');
        $response->assertOk()
            ->assertSee('Для продолжения войдите или зарегистрируйтесь')
            ->assertSee('data-auth-mode="login"', false)
            ->assertSee('data-auth-mode="register"', false)
            ->assertSee('data-auth-redirect="/venues/create?from=catalog&amp;sort=new"', false)
            ->assertSee('id="mskba-auth-dialog-root"', false)
            ->assertDontSee('MSKBA App theme');

        $this->assertSame(url('/venues/create?from=catalog&sort=new'), session('url.intended'));
    }

    public function test_unconfirmed_user_gets_creation_form_instead_of_auth_gate(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($user)->get(route('venues.create'))
            ->assertOk()
            ->assertSee('data-mskba-venue-create', false)
            ->assertDontSee('Для продолжения войдите или зарегистрируйтесь');
    }
}
