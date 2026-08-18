<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserDuplicateAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_rejection_is_written_to_audit_log(): void
    {
        config(['audit.ignore_console' => false]);

        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $candidate = app(UserDuplicateDetector::class)->observeEvidence(
            first: $first,
            second: $second,
            type: UserDuplicateEvidenceTypeEnum::MANUAL,
            normalizedValue: "{$first->id}|{$second->id}",
        );

        $this->actingAs($superadmin)
            ->post(route('admin.users.duplicates.reject', $candidate), [
                'reason' => 'Проверено вручную: разные пользователи.',
            ])
            ->assertRedirect(route('admin.users.duplicates'));

        $this->assertDatabaseHas('user_duplicates', [
            'id' => $candidate->id,
            'status' => UserDuplicateStatusEnum::REJECTED->value,
            'resolved_by' => $superadmin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => UserDuplicate::class,
            'auditable_id' => $candidate->id,
            'event' => 'updated',
        ]);
    }
}
