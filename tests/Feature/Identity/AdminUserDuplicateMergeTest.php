<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminUserDuplicateMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.ignore_console' => false]);
    }

    public function test_superadmin_can_merge_user_duplicate_and_audit_records_the_update(): void
    {
        $superadmin = $this->superadmin();
        [$canonical, $alias, $candidate] = $this->duplicatePair();

        $this->actingAs($superadmin)
            ->post(route('admin.users.duplicates.merge', $candidate), [
                'canonical_user_id' => $canonical->id,
                'confirm_merge' => '1',
            ])
            ->assertRedirect(route('admin.users.duplicates'))
            ->assertSessionHas('success', 'Аккаунты объединены.')
            ->assertSessionHasNoErrors();

        $this->assertNull($canonical->refresh()->canonical_user_id);
        $this->assertSame($canonical->id, $alias->refresh()->canonical_user_id);
        $this->assertDatabaseHas('user_duplicates', [
            'id' => $candidate->id,
            'status' => UserDuplicateStatusEnum::MERGED->value,
            'resolved_by' => $superadmin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => UserDuplicate::class,
            'auditable_id' => $candidate->id,
            'event' => 'updated',
        ]);
    }

    public function test_missing_confirmation_is_visible_and_failed_attempt_is_audited(): void
    {
        $superadmin = $this->superadmin();
        [$canonical, $alias, $candidate] = $this->duplicatePair();

        $response = $this->actingAs($superadmin)
            ->post(route('admin.users.duplicates.merge', $candidate), [
                'canonical_user_id' => $canonical->id,
            ]);

        $response
            ->assertRedirect(route('admin.users.duplicates'))
            ->assertSessionHasErrors([
                'confirm_merge' => 'Подтвердите, что вы проверили оба аккаунта.',
            ])
            ->assertSessionHas('open_user_duplicate_id', $candidate->id);

        $this->assertNull($alias->refresh()->canonical_user_id);
        $this->assertSame(UserDuplicateStatusEnum::PENDING, $candidate->refresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', UserDuplicate::class)
            ->where('auditable_id', $candidate->id)
            ->where('event', 'merge_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('validation', $audit->metadata['reason_type']);
        $this->assertSame(['confirm_merge'], $audit->metadata['validation_fields']);
        $this->assertSame('admin.users.duplicates.merge', $audit->metadata['route']);

        $page = $this->get(route('admin.users.duplicates'));

        $page
            ->assertOk()
            ->assertSee('Не удалось выполнить действие.')
            ->assertSee('Подтвердите, что вы проверили оба аккаунта.')
            ->assertSee('data-admin-action-modal="user-duplicate-'.$candidate->id.'"', false)
            ->assertSee('value="'.$canonical->id.'" checked', false);

        $this->assertMatchesRegularExpression(
            '/data-admin-action-modal="user-duplicate-'.$candidate->id.'"(?![^>]*\shidden)[^>]*>/',
            $page->getContent(),
        );
    }

    public function test_domain_rejection_is_visible_and_failed_attempt_is_audited(): void
    {
        $superadmin = $this->superadmin();
        [$canonical, $alias, $candidate] = $this->duplicatePair();
        $candidate->evidence()->update(['is_active' => false]);

        $this->actingAs($superadmin)
            ->post(route('admin.users.duplicates.merge', $candidate), [
                'canonical_user_id' => $canonical->id,
                'confirm_merge' => '1',
            ])
            ->assertRedirect(route('admin.users.duplicates'))
            ->assertSessionHasErrors([
                'merge' => 'У пары больше нет актуальных подтверждений дублирования.',
            ])
            ->assertSessionHas('open_user_duplicate_id', $candidate->id);

        $this->assertNull($alias->refresh()->canonical_user_id);
        $this->assertSame(UserDuplicateStatusEnum::PENDING, $candidate->refresh()->status);

        $audit = AuditLog::query()
            ->where('auditable_type', UserDuplicate::class)
            ->where('auditable_id', $candidate->id)
            ->where('event', 'merge_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('domain', $audit->metadata['reason_type']);
        $this->assertSame('У пары больше нет актуальных подтверждений дублирования.', $audit->metadata['message']);
    }

    /**
     * @return array{User, User, UserDuplicate}
     */
    private function duplicatePair(): array
    {
        $canonical = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $alias = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $canonical,
            second: $alias,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$canonical->id}|{$alias->id}",
        );

        $this->assertNotNull($candidate);

        return [$canonical, $alias, $candidate];
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
    }
}
