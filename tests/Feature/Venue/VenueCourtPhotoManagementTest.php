<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VenueCourtPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_owner_can_upload_activate_and_delete_court_photos(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue();
        $court = $venue->primaryCourt()->firstOrFail();

        foreach (range(1, 4) as $index) {
            $this->actingAs($owner)->post(route('account.venues.courts.photos.store', [
                $venue->routeIdentifier(),
                $court->routeIdentifier(),
            ]), [
                'photo' => UploadedFile::fake()->image("court-{$index}.jpg", 1200, 600),
            ])->assertRedirect();
        }

        $photos = $court->media()->where('collection', 'gallery')->latest('id')->get();
        $this->assertCount(3, $photos);
        $this->assertSame(1, $photos->where('is_featured', true)->count());

        $featured = $photos->firstWhere('is_featured', true);
        $info = getimagesizefromstring(Storage::disk('public')->get($featured->path));
        $this->assertSame('venue_court', $featured->mediable_type);
        $this->assertSame('image/webp', $featured->mime);
        $this->assertSame(500, $info[0]);
        $this->assertSame(250, $info[1]);

        $target = $photos->last();
        $this->actingAs($owner)->patch(route('account.venues.courts.photos.activate', [
            $venue->routeIdentifier(),
            $court->routeIdentifier(),
            $target->id,
        ]))->assertRedirect();
        $this->assertTrue($target->refresh()->is_featured);

        $this->actingAs($owner)->get(route('account.venues.courts.index', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Фотографии зала')
            ->assertSee('Если фотографии для этого зала не добавлены')
            ->assertSee($target->publicUrl(), false)
            ->assertSee(route('account.venues.courts.photos.destroy', [
                $venue->routeIdentifier(),
                $court->routeIdentifier(),
                $target->id,
            ]), false);

        $path = $target->path;
        $this->actingAs($owner)->delete(route('account.venues.courts.photos.destroy', [
            $venue->routeIdentifier(),
            $court->routeIdentifier(),
            $target->id,
        ]))->assertRedirect();

        $this->assertSoftDeleted('media', ['id' => $target->id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(1, $court->media()->where('collection', 'gallery')->where('is_featured', true)->count());
    }

    public function test_other_user_cannot_manage_court_photos(): void
    {
        Storage::fake('public');
        [, $venue] = $this->ownedVenue();
        $court = $venue->primaryCourt()->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('account.venues.courts.photos.store', [
            $venue->routeIdentifier(),
            $court->routeIdentifier(),
        ]), [
            'photo' => UploadedFile::fake()->image('foreign.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('media', 0);
    }

    /** @return array{User, Venue} */
    private function ownedVenue(): array
    {
        $owner = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
        ]);

        return [$owner, $venue];
    }
}
