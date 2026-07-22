<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VenuePhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_owner_uploads_normalized_venue_photo(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue();

        $this->actingAs($owner)->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('court.jpg', 1200, 600),
        ])->assertRedirect()->assertSessionHas('photo_status', 'Фотография добавлена.');

        $photo = $venue->media()->where('collection', 'gallery')->sole();
        $info = getimagesizefromstring(Storage::disk('public')->get($photo->path));
        $this->assertTrue($photo->is_featured);
        $this->assertSame('image/webp', $photo->mime);
        $this->assertSame(500, $info[0]);
        $this->assertSame(250, $info[1]);

        $this->actingAs($owner)->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee($photo->publicUrl(), false)
            ->assertSee('data-image-upload-auto-submit', false)
            ->assertSee('Загружаем фотографию…')
            ->assertSee(route('account.venues.photos.activate', [$venue->routeIdentifier(), $photo->id]), false)
            ->assertSee(route('account.venues.photos.destroy', [$venue->routeIdentifier(), $photo->id]), false);
    }

    public function test_only_three_photos_are_kept_and_owner_can_activate_and_delete_them(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue();

        foreach (range(1, 4) as $index) {
            $this->actingAs($owner)->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
                'photo' => UploadedFile::fake()->image("court-{$index}.jpg", 640, 480),
            ])->assertRedirect();
        }

        $photos = $venue->media()->where('collection', 'gallery')->latest('id')->get();
        $this->assertCount(3, $photos);
        $this->assertSame(1, $photos->where('is_featured', true)->count());

        $target = $photos->last();
        $this->actingAs($owner)->patch(route('account.venues.photos.activate', [$venue->routeIdentifier(), $target->id]))->assertRedirect();
        $this->assertTrue($target->refresh()->is_featured);

        $path = $target->path;
        $this->actingAs($owner)->delete(route('account.venues.photos.destroy', [$venue->routeIdentifier(), $target->id]))->assertRedirect();
        $this->assertSoftDeleted('media', ['id' => $target->id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(1, $venue->media()->where('collection', 'gallery')->where('is_featured', true)->count());
    }

    public function test_telegram_flow_can_manage_gallery_over_json(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue();

        $response = $this->actingAs($owner)->withHeader('Accept', 'application/json')->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('telegram-court.jpg', 800, 600),
        ])->assertOk()->assertJsonPath('message', 'Фотография добавлена.')->assertJsonCount(1, 'photos');

        $photo = $venue->media()->where('collection', 'gallery')->sole();
        $response->assertJsonPath('photos.0.id', $photo->id)
            ->assertJsonPath('photos.0.is_featured', true)
            ->assertJsonPath('photos.0.activate_url', route('account.venues.photos.activate', [$venue->routeIdentifier(), $photo->id]));

        $this->actingAs($owner)->deleteJson(route('account.venues.photos.destroy', [$venue->routeIdentifier(), $photo->id]))
            ->assertOk()->assertJsonCount(0, 'photos');
    }

    public function test_confirmed_venue_photo_is_published_only_after_revision_approval(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue(VenueStatusEnum::CONFIRMED);
        $published = $venue->media()->create($this->mediaAttributes('venues/published.webp', true));
        Storage::disk('public')->put($published->path, 'published');

        $this->actingAs($owner)->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('proposal.jpg', 1000, 800),
        ])->assertRedirect()->assertSessionHas('photo_status', 'Фотография добавлена.');

        $revision = $venue->revisions()->whereNull('applied_at')->sole();
        $draftPhoto = $revision->media()->where('collection', 'gallery')->sole();
        $this->assertSame(1, $venue->media()->where('collection', 'gallery')->count());

        $this->actingAs($owner)
            ->post(route('account.venues.moderation.submit', $venue->routeIdentifier()), ['message' => 'Новая фотография.'])
            ->assertRedirect();
        $request = ModerationRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($revision->id, $request->venue_revision_id);

        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::ADMIN]);
        $this->actingAs($admin)->get(route('admin.venues'))
            ->assertOk()
            ->assertSee('Что изменится')
            ->assertSee('Фотографии')
            ->assertSee('Новая')
            ->assertSee($draftPhoto->publicUrl(), false)
            ->assertSee('Применить изменения');
        $this->actingAs($admin)
            ->post(route('admin.venues.moderation.approve', $request), ['message' => 'Одобрено.'])
            ->assertRedirect(route('admin.venues'));

        $this->assertNotNull($revision->refresh()->applied_at);
        $this->assertSame($venue->id, $draftPhoto->refresh()->mediable_id);
        $this->assertSame($venue->getMorphClass(), $draftPhoto->mediable_type);
        $this->assertTrue($draftPhoto->is_featured);
    }

    public function test_confirmed_details_remain_public_until_revision_is_approved(): void
    {
        [$owner, $venue] = $this->ownedVenue(VenueStatusEnum::CONFIRMED);
        $originalName = $venue->name;
        $address = $venue->location->address;

        $this->actingAs($owner)->put(route('account.venues.update', $venue->routeIdentifier()), [
            'name' => 'Новое название после проверки', 'type' => $venue->type->value, 'short_description' => 'Новый текст',
            'location' => [
                'raw_address' => $address->full_address, 'address_selected' => '1', 'city' => $address->city,
                'street' => $address->street, 'building' => $address->building, 'postal_code' => $address->postal_code,
                'latitude' => $address->latitude, 'longitude' => $address->longitude, 'metro_station_ids' => [],
            ],
        ])->assertRedirect()->assertSessionHas('status', 'Изменения сохранены в черновик. Отправьте их на модерацию.');

        $this->assertSame($originalName, $venue->refresh()->name);
        $this->assertSame('Новое название после проверки', $venue->revisions()->whereNull('applied_at')->sole()->payload['details']['name']);

        $this->actingAs($owner)
            ->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Изменения готовы к отправке')
            ->assertSee('Отправить изменения на модерацию')
            ->assertSee('Новое название после проверки');

        $this->actingAs($owner)
            ->post(route('account.venues.moderation.submit', $venue->routeIdentifier()), ['message' => 'Проверьте правки.'])
            ->assertRedirect(route('account.venues.status', $venue->routeIdentifier()));

        $request = ModerationRequest::query()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertSee('Что изменится')
            ->assertSee('Название')
            ->assertSee('Было')
            ->assertSee('Станет')
            ->assertSee($originalName)
            ->assertSee('Новое название после проверки')
            ->assertSee('Краткое описание')
            ->assertSee('Новый текст');

        $this->actingAs($owner)
            ->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Площадка находится на модерации')
            ->assertSee('Новое название после проверки')
            ->assertSee('fieldset class="venue-form__fieldset" disabled', false)
            ->assertDontSee('Отправить изменения на модерацию');

        $this->assertSame($request->id, $venue->moderationRequests()->latest('id')->firstOrFail()->id);
    }

    public function test_rejected_revision_does_not_change_public_venue_and_can_be_edited_again(): void
    {
        [$owner, $venue] = $this->ownedVenue(VenueStatusEnum::CONFIRMED);
        $address = $venue->location->address;

        $this->actingAs($owner)->put(route('account.venues.update', $venue->routeIdentifier()), [
            'name' => 'Название из отклонённой ревизии', 'type' => $venue->type->value,
            'location' => [
                'raw_address' => $address->full_address, 'address_selected' => '1', 'city' => $address->city,
                'street' => $address->street, 'building' => $address->building, 'postal_code' => $address->postal_code,
                'latitude' => $address->latitude, 'longitude' => $address->longitude, 'metro_station_ids' => [],
            ],
        ]);
        $this->actingAs($owner)->post(route('account.venues.moderation.submit', $venue->routeIdentifier()));
        $request = ModerationRequest::query()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::ADMIN]);

        $this->actingAs($admin)->post(route('admin.venues.moderation.reject', $request), ['message' => 'Нужно другое фото.']);

        $this->assertNotSame('Название из отклонённой ревизии', $venue->refresh()->name);
        $this->assertNull($venue->revisions()->whereNull('applied_at')->sole()->applied_at);
        $this->actingAs($owner)->get(route('account.venues.edit', $venue->routeIdentifier()))->assertOk()->assertSee('Название из отклонённой ревизии');
    }

    public function test_other_user_cannot_manage_venue_photos(): void
    {
        Storage::fake('public');
        [, $venue] = $this->ownedVenue();
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($other)->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('foreign.jpg'),
        ])->assertForbidden();
        $this->assertDatabaseCount('media', 0);
    }

    public function test_stale_revision_cannot_be_submitted_after_direct_published_change(): void
    {
        Storage::fake('public');
        [$owner, $venue] = $this->ownedVenue(VenueStatusEnum::CONFIRMED);

        $this->actingAs($owner)->post(route('account.venues.photos.store', $venue->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('proposal.jpg'),
        ])->assertRedirect()->assertSessionHas('photo_status', 'Фотография добавлена.');
        $venue->increment('content_version');

        $this->actingAs($owner)->post(route('account.venues.moderation.submit', $venue->routeIdentifier()))
            ->assertRedirect(route('account.venues.status', $venue->routeIdentifier()))
            ->assertSessionHas('error', 'Опубликованная площадка изменилась после создания ревизии. Сохраните изменения заново.');

        $this->assertDatabaseCount('moderation_requests', 0);
    }

    /** @return array{User, Venue} */
    private function ownedVenue(VenueStatusEnum $status = VenueStatusEnum::UNCONFIRMED): array
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'status' => $status,
        ]);

        return [$owner, $venue];
    }

    /** @return array<string, mixed> */
    private function mediaAttributes(string $path, bool $featured = false): array
    {
        return ['collection' => 'gallery', 'source' => 'upload', 'disk' => 'public', 'path' => $path,
            'mime' => 'image/webp', 'size' => 100, 'is_featured' => $featured];
    }
}
