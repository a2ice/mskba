<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipDocumentTypeEnum;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimDocument;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimMessage;
use App\Modules\Venue\Domain\Models\VenueOwnershipDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AttachVenueOwnershipDocumentHandler
{
    public function fromClaimDocument(
        VenueOwnership $ownership,
        VenueOwnershipClaimDocument $source,
        VenueOwnershipDocumentTypeEnum $type,
        User $administrator,
        ?string $note = null,
    ): VenueOwnershipDocument {
        $administrator = $this->administrator($administrator);
        if ($ownership->source_claim_id === null || $source->venue_ownership_claim_id !== $ownership->source_claim_id) {
            throw new InvalidArgumentException('Документ не относится к заявке, по которой создано это владение.');
        }

        return $this->copy(
            ownership: $ownership,
            type: $type,
            administrator: $administrator,
            disk: $source->disk,
            path: $source->path,
            name: $source->name,
            mime: $source->mime,
            size: $source->size,
            note: $note,
            sourceClaimDocumentId: $source->id,
        );
    }

    public function fromMessage(
        VenueOwnership $ownership,
        VenueOwnershipClaimMessage $source,
        VenueOwnershipDocumentTypeEnum $type,
        User $administrator,
        ?string $note = null,
    ): VenueOwnershipDocument {
        $administrator = $this->administrator($administrator);
        $source->loadMissing('conversation');
        if ($source->attachment_path === null || $source->attachment_disk === null) {
            throw new InvalidArgumentException('У сообщения нет вложения.');
        }
        if ($ownership->source_claim_id === null || $source->conversation->venue_ownership_claim_id !== $ownership->source_claim_id) {
            throw new InvalidArgumentException('Сообщение не относится к заявке, по которой создано это владение.');
        }

        return $this->copy(
            ownership: $ownership,
            type: $type,
            administrator: $administrator,
            disk: $source->attachment_disk,
            path: $source->attachment_path,
            name: $source->attachment_name ?? 'document',
            mime: $source->attachment_mime,
            size: $source->attachment_size,
            note: $note,
            sourceClaimMessageId: $source->id,
        );
    }

    private function copy(
        VenueOwnership $ownership,
        VenueOwnershipDocumentTypeEnum $type,
        User $administrator,
        string $disk,
        string $path,
        string $name,
        ?string $mime,
        ?int $size,
        ?string $note,
        ?int $sourceClaimDocumentId = null,
        ?int $sourceClaimMessageId = null,
    ): VenueOwnershipDocument {
        if (! Storage::disk($disk)->exists($path)) {
            throw new InvalidArgumentException('Исходный файл не найден в хранилище.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'document';
        $targetPath = 'venue-ownerships/'.$ownership->public_id.'/'.Str::uuid().'-'.$safeName;
        if (! Storage::disk($disk)->copy($path, $targetPath)) {
            throw new InvalidArgumentException('Не удалось сохранить документ в архив владения.');
        }

        return VenueOwnershipDocument::query()->create([
            'venue_ownership_id' => $ownership->id,
            'type' => $type,
            'source_claim_document_id' => $sourceClaimDocumentId,
            'source_claim_message_id' => $sourceClaimMessageId,
            'added_by_user_id' => $administrator->id,
            'disk' => $disk,
            'path' => $targetPath,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'note' => filled($note) ? trim((string) $note) : null,
        ]);
    }

    private function administrator(User $user): User
    {
        $user = $user->canonical();
        abort_unless(
            $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );

        return $user;
    }
}
