<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Application\Services\UserDuplicateSelfServiceProofStore;
use App\Modules\Identity\Application\UseCases\ResolveUserDuplicateHandler;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class UserCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.user_duplicate_merge_proof_ttl' => 600]);
    }

    public function test_rejected_pair_stays_rejected_for_same_evidence_and_reopens_for_new_evidence(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $detector = app(UserDuplicateDetector::class);
        $resolver = app(ResolveUserDuplicateHandler::class);
        $superadmin = $this->superadmin();

        $candidate = $detector->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY,
            normalizedValue: 'ivan|ivanov|1990-01-01',
        );

        $this->assertNotNull($candidate);
        $resolver->reject($candidate, $superadmin, 'Не один человек');

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

    public function test_stale_profile_evidence_is_deactivated_after_profile_changes(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $first->profile()->create([
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'birth_date' => '1990-01-01',
        ]);
        $second->profile()->create([
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'birth_date' => '1990-01-01',
        ]);

        $candidate = app(UserDuplicateDetector::class)->scan($first)->first();
        $this->assertNotNull($candidate);
        $this->assertTrue($candidate->evidence->contains(
            fn ($evidence): bool => $evidence->type === UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY && $evidence->is_active,
        ));

        $second->profile()->update(['birth_date' => '1991-01-01']);
        app(UserDuplicateDetector::class)->scan($first);

        $candidate->refresh()->load('evidence');
        $this->assertFalse($candidate->evidence->contains(
            fn ($evidence): bool => $evidence->type === UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY && $evidence->is_active,
        ));
        $this->assertNull($candidate->score);
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
            resolvedBy: $this->superadmin(),
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

    public function test_regular_user_cannot_resolve_candidate_through_application_use_case(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $regularUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $resolver = app(ResolveUserDuplicateHandler::class);
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$first->id}|{$second->id}",
        );

        try {
            $resolver->merge($candidate, $first->id, $regularUser);
            $this->fail('Regular user must not be able to merge duplicate candidates directly.');
        } catch (InvalidArgumentException) {
            $this->assertSame(UserDuplicateStatusEnum::PENDING, $candidate->refresh()->status);
        }

        $this->expectException(InvalidArgumentException::class);
        $resolver->reject($candidate->refresh(), $regularUser, 'Unauthorized reject');
    }

    public function test_rejected_candidate_cannot_be_merged(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $detector = app(UserDuplicateDetector::class);
        $resolver = app(ResolveUserDuplicateHandler::class);
        $superadmin = $this->superadmin();
        $candidate = $detector->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$first->id}|{$second->id}",
        );

        $resolver->reject($candidate, $superadmin, 'Не дубли');

        $this->expectException(InvalidArgumentException::class);
        $resolver->merge($candidate, $first->id, $superadmin);
    }

    public function test_self_service_merge_requires_fresh_one_time_telegram_proof(): void
    {
        $current = User::factory()->create();
        $telegramOwner = User::factory()->create();
        $detector = app(UserDuplicateDetector::class);
        $resolver = app(ResolveUserDuplicateHandler::class);
        $candidate = $detector->observeTelegramConflict($current, $telegramOwner, 777001);

        try {
            $resolver->merge(
                candidate: $candidate,
                canonicalUserId: $current->id,
                resolvedBy: $current,
                selfService: true,
                selfServiceSessionId: 'session-a',
            );
            $this->fail('Merge without a fresh proof must fail.');
        } catch (InvalidArgumentException) {
            $this->assertNull($telegramOwner->refresh()->canonical_user_id);
        }

        app(UserDuplicateSelfServiceProofStore::class)->issue(
            candidate: $candidate,
            actor: $current,
            telegramUserId: 777001,
            sessionId: 'session-a',
        );

        $canonical = $resolver->merge(
            candidate: $candidate,
            canonicalUserId: $current->id,
            resolvedBy: $current,
            selfService: true,
            selfServiceSessionId: 'session-a',
        );

        $this->assertSame($current->id, $canonical->id);
        $this->assertSame($current->id, $telegramOwner->refresh()->canonical_user_id);
    }

    public function test_self_service_proof_is_not_issued_for_elevated_system_role(): void
    {
        $current = User::factory()->create();
        $editor = User::factory()->create(['system_role' => UserSystemRoleEnum::EDITOR]);
        $candidate = app(UserDuplicateDetector::class)->observeTelegramConflict($current, $editor, 777002);

        $this->expectException(InvalidArgumentException::class);
        app(UserDuplicateSelfServiceProofStore::class)->issue(
            candidate: $candidate,
            actor: $current,
            telegramUserId: 777002,
            sessionId: 'session-b',
        );
    }

    public function test_self_service_proof_is_not_issued_when_pair_contains_blocked_account(): void
    {
        $current = User::factory()->create();
        $blocked = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        $candidate = app(UserDuplicateDetector::class)->observeTelegramConflict($current, $blocked, 777003);

        $this->expectException(InvalidArgumentException::class);
        app(UserDuplicateSelfServiceProofStore::class)->issue(
            candidate: $candidate,
            actor: $current,
            telegramUserId: 777003,
            sessionId: 'session-c',
        );
    }

    public function test_admin_merge_with_blocked_account_must_keep_blocked_identity_canonical(): void
    {
        $active = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $blocked = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $active,
            second: $blocked,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$active->id}|{$blocked->id}",
        );
        $resolver = app(ResolveUserDuplicateHandler::class);
        $superadmin = $this->superadmin();

        try {
            $resolver->merge($candidate, $active->id, $superadmin);
            $this->fail('Blocked identity must not be unblocked by choosing another canonical account.');
        } catch (InvalidArgumentException) {
            $this->assertNull($active->refresh()->canonical_user_id);
            $this->assertNull($blocked->refresh()->canonical_user_id);
        }

        $result = $resolver->merge($candidate->refresh(), $blocked->id, $superadmin);

        $this->assertSame($blocked->id, $result->id);
        $this->assertSame(UserStatusEnum::BLOCKED, $result->status);
        $this->assertSame($blocked->id, $active->refresh()->canonical_user_id);
    }

    public function test_superadmin_and_system_accounts_cannot_be_merged_through_duplicate_resolution(): void
    {
        $user = User::factory()->create();
        $protectedSuperadmin = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $user,
            second: $protectedSuperadmin,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$user->id}|{$protectedSuperadmin->id}",
        );

        $this->expectException(InvalidArgumentException::class);
        app(ResolveUserDuplicateHandler::class)->merge(
            candidate: $candidate,
            canonicalUserId: $user->id,
            resolvedBy: $this->superadmin(),
        );
    }

    public function test_deleted_account_cannot_be_merged(): void
    {
        $active = User::factory()->create();
        $deleted = User::factory()->create();
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $active,
            second: $deleted,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$active->id}|{$deleted->id}",
        );
        $deleted->delete();

        $this->expectException(InvalidArgumentException::class);
        app(ResolveUserDuplicateHandler::class)->merge(
            candidate: $candidate,
            canonicalUserId: $active->id,
            resolvedBy: $this->superadmin(),
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

    public function test_blocked_canonical_identity_invalidates_existing_web_session(): void
    {
        $blocked = User::factory()->create([
            'status' => UserStatusEnum::BLOCKED,
        ]);
        $alias = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $blocked->id])->save();

        $response = $this->actingAs($alias)->get(route('account'));

        $response->assertForbidden();
        $this->assertGuest();
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
    }
}
