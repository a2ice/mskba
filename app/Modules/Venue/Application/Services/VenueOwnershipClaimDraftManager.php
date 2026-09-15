<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class VenueOwnershipClaimDraftManager
{
    public const MAX_FILES = 10;

    public const MAX_BYTES = 52_428_800;

    /** @return array<string, mixed> */
    public static function uploadRules(): array
    {
        return [
            'ownership_evidence' => ['nullable', 'string', 'max:5000'],
            'ownership_documents' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'ownership_documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function create(Venue $venue, User $applicant, string $evidence, array $files): VenueOwnershipClaim
    {
        return DB::transaction(function () use ($venue, $applicant, $evidence, $files): VenueOwnershipClaim {
            $claim = VenueOwnershipClaim::query()->create([
                'venue_id' => $venue->id, 'applicant_user_id' => $applicant->canonical()->id,
                'status' => VenueOwnershipClaimStatusEnum::DRAFT, 'evidence' => '',
                'submitted_at' => null, 'active_marker' => true,
            ]);

            return $this->save($claim, $applicant, $evidence, $files);
        });
    }

    /** @param list<UploadedFile> $files */
    public function save(VenueOwnershipClaim $claim, User $applicant, string $evidence, array $files): VenueOwnershipClaim
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($claim, $applicant, $evidence, $files, &$paths): VenueOwnershipClaim {
                Venue::query()->lockForUpdate()->findOrFail($claim->venue_id);
                $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($claim->id);
                abort_unless($applicant->canonical()->isSameIdentity($claim->applicant_user_id), 403);
                abort_unless($claim->status === VenueOwnershipClaimStatusEnum::DRAFT, 409, 'Редактировать можно только черновик заявки.');
                $documents = $claim->documents()->get();
                if ($documents->count() + count($files) > self::MAX_FILES
                    || $documents->sum('size') + array_sum(array_map(fn (UploadedFile $file) => $file->getSize(), $files)) > self::MAX_BYTES) {
                    throw ValidationException::withMessages(['ownership_documents' => 'Можно сохранить до 10 документов общим размером до 50 МБ.']);
                }
                $claim->update(['evidence' => trim($evidence)]);
                foreach ($files as $file) {
                    $path = 'venue-ownership-claims/'.$claim->public_id.'/documents/'.Str::uuid();
                    $paths[] = $path;
                    if (! Storage::disk('local')->put($path, $file->get())) {
                        throw new RuntimeException('Не удалось сохранить документ. Попробуйте ещё раз.');
                    }
                    $claim->documents()->create([
                        'path' => $path,
                        'name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                    ]);
                }

                return $claim->load('documents');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }
    }

    public function discardFiles(VenueOwnershipClaim $claim): void
    {
        Storage::disk('local')->delete($claim->documents->pluck('path')->all());
    }
}
