<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Application\UseCases\ResolveUserDuplicateHandler;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_pair_stays_rejected_for_same_evidence_and_reopens_for_new_evidence(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $detector = app(UserDuplicateDetector::class);
        $resolver = app(ResolveUserDuplicateHandler::class);

        $candidate = $detector->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY,
            normalizedValue: 'ivan|ivanov|1990-01-01',
        );

        $this->assertNotNull($candidate);
        $resolver->reject($candidate, null, 'Не один человек');

        $candidate = $detector->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY,
            normalizedValue: 'ivan|ivanov|1990-01-01',
        );

        $this->assertSame(UserDuplicateStatusEnum::REJECTED, $candidate?->status);

        $candidate = $detector->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::VERIFIED_PHONE,
            normalizedValue: '+79991234567',
        );

        $this->assertSame(UserDuplicateStatusEnum::PENDING, $candidate?->status);
        $this->assertNull($candidate?->resolved_at);
    }

    public function test_merge_marks_alias_and_flattens_existing_aliases_without_rewriting_old_relations(): void
    {
        $canonical = User::factory()->create();
        $source = User::factory()->create();
        $sourceAlias = User::factory()->create();
        $sourceAlias->forceFill(['canonical_user_id' => $source->id])->save();

        $contact = $source->contacts()->create([
            'type' => 'phone',
            'value' => '+79990000001',
            'is_primary' => true,
        ]);

        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $canonical,
            second: $source,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$canonical->id}|{$source->id}",
        );

        $result = app(ResolveUserDuplicateHandler::class)->merge(
            candidate: $candidate,
            canonicalUserId: $canonical->id,
            resolvedBy: null,
        );

        $this->assertSame($canonical->id, $result->id);
        $this->assertSame($canonical->id, $source->refresh()->canonical_user_id);
        $this->assertSame($canonical->id, $sourceAlias->refresh()->canonical_user_id);
        $this->assertSame($source->id, $contact->refresh()->contactable_id);
        $this->assertEqualsCanonicalizing(
            [$canonical->id, $source->id, $sourceAlias->id],
            $canonical->refresh()->identityIds(),
        );
    }

    public function test_login_with_alias_credentials_authenticates_as_canonical_user(): void
    {
        $canonical = User::factory()->create([
            'username' => 'canonical_user',
            'password' => 'canonical-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias = User::factory()->create([
            'username' => 'alias_user',
            'password' => 'alias-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $response = $this->post(route('auth.login'), [
            'login' => 'alias_user',
            'password' => 'alias-password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($canonical);
    }

    public function test_existing_alias_session_is_resolved_to_canonical_user_for_web_request(): void
    {
        $canonical = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $response = $this->actingAs($alias)->get(route('account'));

        $response->assertOk();
        $this->assertSame($canonical->id, auth()->user()?->id);
    }
}
