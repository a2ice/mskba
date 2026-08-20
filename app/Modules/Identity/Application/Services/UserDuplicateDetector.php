<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Modules\Identity\Domain\Models\UserDuplicateEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserDuplicateDetector
{
    /**
     * Scan strong, indexable identities and exact profile identity for one user.
     * Detection never performs canonicalization by itself.
     *
     * @return Collection<int, UserDuplicate>
     */
    public function scan(User $user): Collection
    {
        $user = $user->canonical();
        $detected = collect();
        $observedScanEvidence = [];

        $items = array_merge(
            $this->verifiedContactEvidence($user),
            $this->profileEvidence($user),
        );

        foreach ($items as $item) {
            $candidate = $this->observeEvidence(
                first: $user,
                second: $item['user'],
                type: $item['type'],
                normalizedValue: $item['value'],
                metadata: $item['metadata'],
            );

            if ($candidate === null) {
                continue;
            }

            $detected->put($candidate->id, $candidate);
            $observedScanEvidence[$this->scanEvidenceKey(
                (int) $candidate->id,
                $item['type'],
                $this->evidenceValueHash($item['type'], $item['value']),
            )] = true;
        }

        $this->deactivateMissingScanEvidence($user, array_keys($observedScanEvidence));

        return $detected
            ->keys()
            ->map(fn ($id) => UserDuplicate::query()->with('evidence')->find($id))
            ->filter()
            ->values();
    }

    public function observeTelegramConflict(User $currentUser, User $telegramOwner, int $telegramUserId): ?UserDuplicate
    {
        return $this->observeEvidence(
            first: $currentUser,
            second: $telegramOwner,
            type: UserDuplicateEvidenceTypeEnum::TELEGRAM_IDENTITY,
            normalizedValue: (string) $telegramUserId,
            metadata: [
                'telegram_user_id' => $telegramUserId,
                'self_service_user_id' => (int) $currentUser->canonical()->id,
                'telegram_owner_user_id' => (int) $telegramOwner->canonical()->id,
                'source' => 'signed_telegram_auth',
            ],
        );
    }

    public function observeVkConflict(User $currentUser, User $vkOwner, string $vkUserId): ?UserDuplicate
    {
        return $this->observeEvidence(
            first: $currentUser,
            second: $vkOwner,
            type: UserDuplicateEvidenceTypeEnum::VK_IDENTITY,
            normalizedValue: $vkUserId,
            metadata: [
                'vk_user_id' => $vkUserId,
                'self_service_user_id' => (int) $currentUser->canonical()->id,
                'vk_owner_user_id' => (int) $vkOwner->canonical()->id,
                'source' => 'signed_vk_id_auth',
            ],
        );
    }

    /**
     * Records one immutable evidence identity. A rejected candidate is reopened
     * whenever the aggregate hash of current evidence differs from the hash
     * that was reviewed previously.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function observeEvidence(
        User $first,
        User $second,
        UserDuplicateEvidenceTypeEnum $type,
        string $normalizedValue,
        array $metadata = [],
    ): ?UserDuplicate {
        $first = $first->canonical();
        $second = $second->canonical();

        if ($first->isSameIdentity($second)) {
            return null;
        }

        [$userId, $duplicateUserId] = $this->orderedPair((int) $first->id, (int) $second->id);
        $valueHash = $this->evidenceValueHash($type, $normalizedValue);

        return DB::transaction(function () use ($userId, $duplicateUserId, $type, $valueHash, $metadata): UserDuplicate {
            UserDuplicate::query()->insertOrIgnore([
                'user_id' => $userId,
                'duplicate_user_id' => $duplicateUserId,
                'status' => UserDuplicateStatusEnum::PENDING->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $candidate = UserDuplicate::query()
                ->where('user_id', $userId)
                ->where('duplicate_user_id', $duplicateUserId)
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();
            $evidence = UserDuplicateEvidence::query()->firstOrNew([
                'user_duplicate_id' => $candidate->id,
                'type' => $type,
                'value_hash' => $valueHash,
            ]);

            if (! $evidence->exists) {
                $evidence->first_seen_at = $now;
            }

            $evidence->forceFill([
                'metadata' => array_replace($evidence->metadata ?? [], $metadata),
                'is_active' => true,
                'last_seen_at' => $now,
            ])->save();

            $this->refreshCandidateAggregate($candidate);

            return $candidate->refresh()->load('evidence');
        });
    }

    /**
     * Verified contacts are logical identity data: after a merge they may
     * physically remain attached to any alias and must still participate in
     * duplicate detection for the canonical user.
     *
     * @return list<array{user: User, type: UserDuplicateEvidenceTypeEnum, value: string, metadata: array<string, mixed>}>
     */
    private function verifiedContactEvidence(User $user): array
    {
        $result = [];
        $identityIds = $user->identityIds();
        $contacts = Contact::query()
            ->where('contactable_type', 'user')
            ->whereIn('contactable_id', $identityIds)
            ->whereNotNull('verified_at')
            ->whereIn('type', [
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::PHONE->value,
                ContactTypeEnum::TELEGRAM->value,
            ])
            ->get();

        foreach ($contacts as $contact) {
            $type = match ($contact->type) {
                ContactTypeEnum::EMAIL => UserDuplicateEvidenceTypeEnum::VERIFIED_EMAIL,
                ContactTypeEnum::PHONE => UserDuplicateEvidenceTypeEnum::VERIFIED_PHONE,
                ContactTypeEnum::TELEGRAM => UserDuplicateEvidenceTypeEnum::TELEGRAM_IDENTITY,
            };

            $otherUserIds = Contact::query()
                ->where('contactable_type', 'user')
                ->where('type', $contact->type->value)
                ->where('value', $contact->value)
                ->whereNotNull('verified_at')
                ->whereNotIn('contactable_id', $identityIds)
                ->pluck('contactable_id')
                ->map(fn ($id): int => (int) $id)
                ->unique();

            foreach (User::query()->whereIn('id', $otherUserIds)->get() as $otherUser) {
                if ($user->isSameIdentity($otherUser)) {
                    continue;
                }

                $result[] = [
                    'user' => $otherUser,
                    'type' => $type,
                    'value' => (string) $contact->value,
                    'metadata' => [
                        'contact_type' => $contact->type->value,
                        'contact_id' => (int) $contact->id,
                        'contact_owner_user_id' => (int) $contact->contactable_id,
                        'source' => 'verified_contact',
                    ],
                ];
            }
        }

        return $result;
    }

    /**
     * @return list<array{user: User, type: UserDuplicateEvidenceTypeEnum, value: string, metadata: array<string, mixed>}>
     */
    private function profileEvidence(User $user): array
    {
        $profile = $user->profile;

        if (
            $profile === null
            || blank($profile->first_name)
            || blank($profile->last_name)
            || $profile->birth_date === null
        ) {
            return [];
        }

        $firstName = $this->normalizeText((string) $profile->first_name);
        $lastName = $this->normalizeText((string) $profile->last_name);
        $middleName = $this->normalizeText((string) ($profile->middle_name ?? ''));
        $birthDate = $profile->birth_date->format('Y-m-d');

        $profiles = Profile::query()
            ->where('user_id', '!=', $user->id)
            ->whereDate('birth_date', $birthDate)
            ->get()
            ->filter(function (Profile $other) use ($firstName, $lastName, $middleName): bool {
                return $this->normalizeText((string) $other->first_name) === $firstName
                    && $this->normalizeText((string) $other->last_name) === $lastName
                    && $this->normalizeText((string) ($other->middle_name ?? '')) === $middleName;
            });

        $result = [];
        $value = implode('|', [$lastName, $firstName, $middleName, $birthDate]);

        foreach (User::query()->whereIn('id', $profiles->pluck('user_id'))->get() as $otherUser) {
            if ($user->isSameIdentity($otherUser)) {
                continue;
            }

            $result[] = [
                'user' => $otherUser,
                'type' => UserDuplicateEvidenceTypeEnum::PROFILE_IDENTITY,
                'value' => $value,
                'metadata' => [
                    'birth_date' => $birthDate,
                    'source' => 'exact_profile_identity',
                ],
            ];
        }

        return $result;
    }

    /** @param list<string> $observedKeys */
    private function deactivateMissingScanEvidence(User $user, array $observedKeys): void
    {
        $candidateIds = UserDuplicate::query()
            ->where(function ($query) use ($user): void {
                $query
                    ->where('user_id', $user->id)
                    ->orWhere('duplicate_user_id', $user->id);
            })
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return;
        }

        $observed = array_fill_keys($observedKeys, true);
        $changedCandidateIds = [];

        UserDuplicateEvidence::query()
            ->whereIn('user_duplicate_id', $candidateIds)
            ->where('is_active', true)
            ->get()
            ->each(function (UserDuplicateEvidence $evidence) use ($observed, &$changedCandidateIds): void {
                $source = $evidence->metadata['source'] ?? null;
                if (! in_array($source, ['verified_contact', 'exact_profile_identity'], true)) {
                    return;
                }

                $key = $this->scanEvidenceKey(
                    (int) $evidence->user_duplicate_id,
                    $evidence->type,
                    (string) $evidence->value_hash,
                );

                if (isset($observed[$key])) {
                    return;
                }

                $evidence->forceFill(['is_active' => false])->save();
                $changedCandidateIds[(int) $evidence->user_duplicate_id] = true;
            });

        foreach (array_keys($changedCandidateIds) as $candidateId) {
            $candidate = UserDuplicate::query()->find($candidateId);
            if ($candidate !== null) {
                $this->refreshCandidateAggregate($candidate);
            }
        }
    }

    private function refreshCandidateAggregate(UserDuplicate $candidate): void
    {
        $activeEvidence = $candidate->evidence()->where('is_active', true)->get(['type', 'value_hash']);
        $evidenceHash = $this->aggregateEvidenceHash($activeEvidence);
        $score = $this->aggregateScore($activeEvidence);
        $updates = [
            'evidence_hash' => $evidenceHash,
            'score' => $score,
        ];

        if (
            $candidate->status === UserDuplicateStatusEnum::REJECTED
            && $activeEvidence->isNotEmpty()
            && $candidate->resolved_evidence_hash !== $evidenceHash
        ) {
            $updates = array_replace($updates, [
                'status' => UserDuplicateStatusEnum::PENDING,
                'resolved_by' => null,
                'resolved_at' => null,
            ]);
        }

        $candidate->forceFill($updates)->save();
    }

    private function aggregateEvidenceHash(Collection $activeEvidence): string
    {
        $parts = $activeEvidence
            ->map(fn (UserDuplicateEvidence $evidence): string => $evidence->type->value.':'.$evidence->value_hash)
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode('|', $parts));
    }

    private function aggregateScore(Collection $activeEvidence): ?int
    {
        if ($activeEvidence->isEmpty()) {
            return null;
        }

        return (int) $activeEvidence
            ->map(fn (UserDuplicateEvidence $evidence): int => $evidence->type->defaultScore())
            ->max();
    }

    private function evidenceValueHash(UserDuplicateEvidenceTypeEnum $type, string $value): string
    {
        return hash_hmac(
            'sha256',
            $type->value.'|'.$this->normalizeEvidenceValue($value),
            (string) config('app.key'),
        );
    }

    private function scanEvidenceKey(
        int $candidateId,
        UserDuplicateEvidenceTypeEnum $type,
        string $valueHash,
    ): string {
        return $candidateId.'|'.$type->value.'|'.$valueHash;
    }

    /** @return array{int, int} */
    private function orderedPair(int $first, int $second): array
    {
        return $first < $second ? [$first, $second] : [$second, $first];
    }

    private function normalizeEvidenceValue(string $value): string
    {
        return $this->normalizeText($value);
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
