<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VenueOwnershipClaimDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_scans_remain_private_and_follow_the_same_claim_into_review(): void
    {
        Storage::fake('local');
        Event::fake([VenueOwnershipClaimSubmitted::class]);
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $this->actingAs($applicant)->postJson(route('venues.store'), [
            ...$this->payload(), 'ownership_documents' => [UploadedFile::fake()->image('скан.jpg'), UploadedFile::fake()->create('договор.pdf', 1, 'application/pdf')],
        ])->assertCreated()->assertJsonPath('ownership_claim.status', 'draft');
        $claim = VenueOwnershipClaim::query()->with('documents')->sole();
        $document = $claim->documents->first();
        $this->assertSame(2, $claim->documents->count());
        $this->assertSame($applicant->id, $claim->applicant_user_id);
        $this->assertNull($claim->submitted_at);
        $this->assertDatabaseCount('venue_ownerships', 0);
        $this->assertDatabaseCount('venue_ownership_claim_conversations', 0);
        Storage::disk('local')->assertExists($document->path);
        Event::assertNotDispatched(VenueOwnershipClaimSubmitted::class);
        $download = route('account.venue-ownership.documents.download', [$claim, $document]);
        $this->get(route('account.venue-ownership.show', $claim))->assertOk();
        $this->get($download)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->get($download)->assertForbidden();
        $this->actingAs($admin)->get($download)->assertForbidden();
        $this->get(route('admin.venue-ownership.index', ['queue' => 'completed']))->assertDontSee($claim->public_id);

        $evidence = 'Я представляю площадку и могу подтвердить полномочия приложенным документом.';
        $this->actingAs($applicant)->post(route('account.venue-ownership.draft.save', $claim), [
            'intent' => 'save', 'ownership_evidence' => $evidence,
            'ownership_documents' => [UploadedFile::fake()->image('ещё.png')],
        ])->assertRedirect();
        $extra = $claim->documents()->reorder('id', 'desc')->first();
        $this->delete(route('account.venue-ownership.documents.destroy', [$claim, $extra]))->assertRedirect();
        $this->assertDatabaseMissing('venue_ownership_claim_documents', ['id' => $extra->id]);
        $this->post(route('account.venue-ownership.draft.save', $claim), [
            'intent' => 'submit', 'ownership_evidence' => $evidence,
        ])->assertRedirect(route('account.venue-ownership.show', $claim));
        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->submitted_at);
        $this->assertDatabaseCount('venue_ownership_claims', 1);
        $this->assertDatabaseHas('venue_ownership_claim_documents', ['id' => $document->id, 'venue_ownership_claim_id' => $claim->id]);
        $this->assertDatabaseCount('venue_ownerships', 0);
        $this->delete(route('account.venue-ownership.documents.destroy', [$claim, $document]))->assertStatus(409);
        $this->post(route('account.venue-ownership.draft.save', $claim), ['intent' => 'save'])->assertStatus(409);
        $this->actingAs($admin)->get($download)->assertOk();
        $this->get(route('account.venue-ownership.show', $claim))->assertOk()->assertSee($document->name);
        $otherClaim = $claim->fresh()->replicate(['public_id']);
        $otherClaim->applicant_user_id = $other->id;
        $otherClaim->save();
        $this->get(route('account.venue-ownership.documents.download', [$otherClaim, $document]))->assertNotFound();
    }

    public function test_unverified_applicant_can_save_documents_but_cannot_submit_until_verified(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($user)->post(route('venues.store'), $this->payload())->assertRedirect();
        $claim = VenueOwnershipClaim::query()->sole();
        $data = ['intent' => 'submit', 'ownership_evidence' => 'Я представляю площадку и подтверждаю мои полномочия документом.',
            'ownership_documents' => [UploadedFile::fake()->image('скан.png')]];
        $this->post(route('account.venue-ownership.draft.save', $claim), $data)->assertSessionHas('error');
        $this->assertSame(VenueOwnershipClaimStatusEnum::DRAFT, $claim->fresh()->status);
        $this->assertSame(1, $claim->documents()->count());
        $user->update(['status' => UserStatusEnum::CONFIRMED]);
        unset($data['ownership_documents']);
        $this->actingAs($user->fresh())->post(route('account.venue-ownership.draft.save', $claim), $data)->assertRedirect();
        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->fresh()->status);
        $this->assertSame(1, $claim->documents()->count());
    }

    public function test_contributor_and_invalid_scan_cannot_create_claim_or_venue(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user)->post(route('venues.store'), [
            ...$this->payload(), 'creation_role' => 'contributor',
            'ownership_documents' => [UploadedFile::fake()->image('scan.jpg')],
        ])->assertSessionHasErrors('creation_role');
        $this->post(route('venues.store'), [...$this->payload(),
            'ownership_documents' => [UploadedFile::fake()->create('scan.txt', 1, 'text/plain')],
        ])->assertSessionHasErrors('ownership_documents.0');
        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('venue_ownership_claims', 0);
        $this->post(route('venues.store'), [...$this->payload(), 'creation_role' => 'contributor'])->assertRedirect();
        $this->assertDatabaseCount('venue_ownership_claims', 0);
    }

    public function test_storage_failure_rolls_back_venue_location_claim_and_previously_written_scans(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $calls = 0;
        Storage::partialMock()->shouldReceive('disk')->with('local')->andReturn(
            \Mockery::mock($disk)->makePartial()->shouldReceive('put')->andReturnUsing(function ($path, $contents) use ($disk, &$calls) {
                return ++$calls === 1 ? $disk->put($path, $contents) : false;
            })->getMock()
        );
        $this->actingAs(User::factory()->create(['status' => UserStatusEnum::CONFIRMED]))
            ->post(route('venues.store'), [...$this->payload(), 'ownership_documents' => [
                UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg'),
            ]])->assertSessionHas('error');
        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('locations', 0);
        $this->assertDatabaseCount('venue_ownership_claims', 0);
        $this->assertDatabaseCount('venue_ownership_claim_documents', 0);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_attachment_quota_prevents_partial_creation_and_draft_can_still_be_saved_without_scans(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['status' => UserStatusEnum::CONFIRMED]));
        $files = array_map(fn ($index) => UploadedFile::fake()->create("scan-{$index}.pdf", 10240, 'application/pdf'), range(1, 6));
        $this->post(route('venues.store'), [...$this->payload(), 'ownership_documents' => $files])
            ->assertSessionHasErrors('ownership_documents');
        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('venue_ownership_claims', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->post(route('venues.store'), $this->payload())->assertRedirect();
        $claim = VenueOwnershipClaim::query()->sole();
        foreach (range(1, 10) as $index) {
            $claim->documents()->create(['path' => "existing/{$index}", 'name' => "scan-{$index}.pdf", 'mime' => 'application/pdf', 'size' => 1]);
        }
        $this->post(route('account.venue-ownership.draft.save', $claim), ['intent' => 'save',
            'ownership_documents' => [UploadedFile::fake()->image('extra.jpg')],
        ])->assertSessionHasErrors('ownership_documents');
        $this->assertSame(10, $claim->documents()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->post(route('account.venue-ownership.cancel', $claim))->assertRedirect();
        $this->assertSame(VenueOwnershipClaimStatusEnum::CANCELLED, $claim->fresh()->status);
        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $this->actingAs($admin)->get(route('account.venue-ownership.show', $claim))->assertForbidden();
        $this->get(route('account.venue-ownership.documents.download', [$claim, $claim->documents()->first()]))->assertForbidden();
    }

    private function payload(): array
    {
        return ['name' => 'Новая площадка', 'type' => VenueTypeEnum::STREET_COURT->value,
            'creation_role' => 'representative', 'location' => [
                'raw_address' => 'Москва, Тестовая улица, 99', 'address_selected' => '1',
                'city' => 'Москва', 'street' => 'Тестовая улица', 'building' => '99',
                'latitude' => 55.7001, 'longitude' => 37.6001,
            ]];
    }
}
