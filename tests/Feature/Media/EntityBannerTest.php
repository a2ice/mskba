<?php

namespace Tests\Feature\Media;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\Media\Application\Services\EntityBannerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EntityBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function owner(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
    }

    private function league(User $owner): League
    {
        return League::query()->create([
            'name' => 'Лига с баннером',
            'alias' => 'banner-test-'.uniqid(),
            'status' => 'active',
            'owner_user_id' => $owner->id,
        ]);
    }

    private function url(League $league): string
    {
        return route('entity-banners.update', [
            'context' => 'account', 'type' => 'league', 'id' => $league->id,
        ]);
    }

    private function validCrop(): array
    {
        return ['x' => 0, 'y' => 0.15, 'width' => 1, 'height' => 0.444444];
    }

    public function test_owner_uploads_preserved_original_and_public_cropped_webp(): void
    {
        $owner = $this->owner();
        $league = $this->league($owner);
        $this->actingAs($owner);

        $this->post($this->url($league), [
            'image' => UploadedFile::fake()->image('banner.jpg', 1200, 900),
            'crop' => $this->validCrop(),
        ])->assertOk()->assertJsonPath('message', 'Баннер сохранён.');

        $banner = $league->fresh()->banner;
        $this->assertNotNull($banner);
        $this->assertSame('banner', $banner->collection);
        $this->assertSame('webp', pathinfo($banner->path, PATHINFO_EXTENSION));
        $this->assertSame('local', $banner->original_disk);
        $this->assertSame('public', $banner->disk);
        $this->assertSame(1200, $banner->width);
        $this->assertSame(400, $banner->height);
        Storage::disk('local')->assertExists($banner->original_path);
        Storage::disk('public')->assertExists($banner->path);

        $this->get(route('entity-banners.source', [
            'context' => 'account', 'type' => 'league', 'id' => $league->id,
        ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->post($this->url($league), [
            'crop' => ['x' => 0.05, 'y' => 0.2, 'width' => 0.9, 'height' => 0.4],
        ])->assertOk();

        $this->assertNotSame($banner->path, $league->fresh()->banner->path);
        $this->assertSame(1, $league->fresh()->banner()->count());

        $this->delete(route('entity-banners.destroy', [
            'context' => 'account', 'type' => 'league', 'id' => $league->id,
        ]))->assertOk();
        $this->assertNull($league->fresh()->banner);
    }

    public function test_another_user_cannot_read_source_upload_or_delete_banner(): void
    {
        $owner = $this->owner();
        $attacker = $this->owner();
        $league = $this->league($owner);

        $this->actingAs($owner);
        $upload = UploadedFile::fake()->image('banner.jpg', 1200, 900);
        app(EntityBannerManager::class)->replace(
            $league, (string) file_get_contents($upload->getRealPath()),
            $this->validCrop(),
        );

        $this->actingAs($attacker);
        $params = ['context' => 'account', 'type' => 'league', 'id' => $league->id];

        $this->get(route('entity-banners.source', $params))->assertForbidden();
        $this->post(route('entity-banners.update', $params), [
            'crop' => $this->validCrop(),
        ])->assertForbidden();
        $this->delete(route('entity-banners.destroy', $params))->assertForbidden();
        $this->assertNotNull($league->fresh()->banner);
    }

    public function test_invalid_crop_is_rejected_without_creating_media(): void
    {
        $owner = $this->owner();
        $league = $this->league($owner);
        $this->actingAs($owner);

        $this->post($this->url($league), [
            'image' => UploadedFile::fake()->image('banner.jpg', 1200, 900),
            'crop' => ['x' => 0.9, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertNull($league->fresh()->banner);
    }

    public function test_each_entity_exposes_independent_banner_relation(): void
    {
        foreach ([
            \App\Modules\League\Domain\Models\League::class,
            \App\Modules\League\Domain\Models\LeagueSeason::class,
            \App\Modules\Team\Domain\Models\Team::class,
            \App\Modules\Tournament\Domain\Models\Tournament::class,
            \App\Modules\Venue\Domain\Models\Venue::class,
            \App\Modules\Identity\Domain\Models\Profile::class,
        ] as $class) {
            $this->assertInstanceOf(
                \Illuminate\Database\Eloquent\Relations\MorphOne::class,
                (new $class())->banner(),
            );
        }
    }

    public function test_owner_can_manage_banner_of_own_league_and_its_season_but_not_another_parent(): void
    {
        $owner = $this->owner();
        $league = $this->league($owner);
        $second = $this->league($this->owner());
        $season = $league->seasons()->create(['name' => 'Сезон 2026', 'status' => 'active']);
        $this->actingAs($owner);

        $this->get(route('account.leagues.banner', $league->id))->assertOk();
        $this->get(route('account.leagues.seasons.banner', [$league->id, $season->id]))->assertOk();
        $this->get(route('account.leagues.seasons.banner', [$second->id, $season->id]))->assertNotFound();
        $this->get(route('account.leagues.banner', $second->id))->assertForbidden();
    }

    public function test_login_and_backoffice_rights_remain_separate(): void
    {
        $league = $this->league($this->owner());
        $this->post($this->url($league), ['crop' => $this->validCrop()])->assertRedirect();

        $this->actingAs($this->owner());
        $this->post(route('entity-banners.update', [
            'context' => 'backoffice', 'type' => 'league', 'id' => $league->id,
        ]), ['crop' => $this->validCrop()])->assertForbidden();
    }
}
